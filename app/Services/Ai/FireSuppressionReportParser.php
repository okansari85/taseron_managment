<?php

namespace App\Services\Ai;

use App\Models\FireSuppressionInventoryItem;
use App\Models\FireSuppressionReportFinding;
use DateTime;

// Yangın Söndürme Sistemleri Raporu için DOCUMENT PARSER — YscReportParser
// ile aynı ayrım prensibi: AI (NvidiaNimClient) sadece ham bir tahmin
// üretir, bu sınıf onu normalize eder / doğrular / belirsizleri işaretler.
// Matching Engine (MatchingEngine + FireSuppressionMatchingProfile) ve
// uygunsuzluk kapsam çözümleme (FireSuppressionReportService) sadece bu
// sınıfın çıktısını görür.
class FireSuppressionReportParser
{
    public function __construct(
        private NvidiaNimClient $ai,
    ) {
    }

    public function parse(string $rawText): array
    {
        $categories = FireSuppressionInventoryItem::CATEGORIES;
        $guess = $this->ai->extractStructuredJson($this->buildPrompt($categories), $rawText);

        return [
            'control_date' => $this->normalizeDate($guess['control_date'] ?? null),
            'next_control_date' => $this->normalizeDate($guess['next_control_date'] ?? null),
            'overall_result' => $this->normalizeResult($guess['overall_result'] ?? null),
            'covered_categories' => $this->normalizeCategories(is_array($guess['covered_categories'] ?? null) ? $guess['covered_categories'] : [], $categories),
            'equipment' => $this->normalizeEquipment(is_array($guess['equipment'] ?? null) ? $guess['equipment'] : [], $categories),
            'findings' => $this->normalizeFindings(is_array($guess['findings'] ?? null) ? $guess['findings'] : [], $categories),
        ];
    }

    private function buildPrompt(array $categories): string
    {
        $categoryList = implode(', ', $categories);

        return <<<PROMPT
Sen bir yangın söndürme sistemleri periyodik kontrol raporundan HAM veri çıkaran bir asistansın.
Sistemler/ekipmanlar için SADECE şu kategori kodlarını kullan: {$categoryList}.
Aşağıdaki metinden şu JSON şemasına göre veri çıkar, SADECE JSON döndür:
{
  "control_date": "metinde geçen kontrol/muayene tarihi, aynen metindeki gibi",
  "next_control_date": "metinde geçen sonraki kontrol tarihi, aynen metindeki gibi",
  "overall_result": "metinde geçen genel sonuç ifadesi, aynen metindeki gibi",
  "covered_categories": ["kategori_kodu", ...],
  "equipment": [{"code": "string", "category": "kategori_kodu", "location_note": "string", "brand": "marka", "model": "model", "serial_no": "seri no", "result": "bu ekipman için sonuç ifadesi"}],
  "findings": [{"category": "kategori_kodu", "control_item": "string", "description": "string", "scope": "specific"|"area"|"unknown", "area_note": "string", "equipment_codes": ["string", ...]}]
}
Tarihi veya sonuç ifadesini normalize etmeye ÇALIŞMA — metinde ne yazıyorsa onu aynen döndür, bu işi başka bir katman yapacak.
Metinde açıkça olmayan bilgiyi ASLA uydurma, null bırak. "scope" alanını sadece metinde kapsam açıkça belirtilmişse "specific"/"area" yap, aksi halde "unknown" kullan — asla otomatik olarak "all" üretme.
PROMPT;
    }

    private function normalizeDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = trim($value);

        foreach (['d.m.Y', 'd/m/Y', 'Y-m-d', 'd.m.y', 'd-m-Y'] as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date instanceof DateTime && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    private function normalizeResult(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = mb_strtolower(trim($value), 'UTF-8');

        if (str_contains($value, 'uygun değil') || str_contains($value, 'uygunsuz') || str_contains($value, 'ret')) {
            return 'uygun_degil';
        }

        if (str_contains($value, 'uygun')) {
            return 'uygun';
        }

        return null;
    }

    private function normalizeString(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return ($value !== null && $value !== '') ? $value : null;
    }

    private function normalizeCategory(?string $value, array $categories): ?string
    {
        $value = $this->normalizeString($value);

        return ($value !== null && in_array($value, $categories, true)) ? $value : null;
    }

    private function normalizeCategories(array $values, array $categories): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($v) => $this->normalizeCategory(is_string($v) ? $v : null, $categories),
            $values
        ))));
    }

    private function normalizeEquipment(array $rawEquipment, array $categories): array
    {
        return array_values(array_map(function ($item) use ($categories): array {
            $item = is_array($item) ? $item : [];
            $code = $this->normalizeString($item['code'] ?? null);
            $brand = $this->normalizeString($item['brand'] ?? null);
            $model = $this->normalizeString($item['model'] ?? null);
            $locationNote = $this->normalizeString($item['location_note'] ?? null);

            return [
                'code' => $code,
                'category' => $this->normalizeCategory($item['category'] ?? null, $categories),
                'location_note' => $locationNote,
                'brand' => $brand,
                'model' => $model,
                'serial_no' => $this->normalizeString($item['serial_no'] ?? null),
                'result' => $this->normalizeResult($item['result'] ?? null),
                // Ne kesin (kod) ne aday (marka/model/konum) araması için
                // hiçbir dayanağı yoksa belirsiz olarak işaretlenir.
                'is_uncertain' => $code === null && $brand === null && $model === null && $locationNote === null,
            ];
        }, $rawEquipment));
    }

    private function normalizeFindings(array $rawFindings, array $categories): array
    {
        $scopes = FireSuppressionReportFinding::SCOPES;

        return array_values(array_map(function ($item) use ($categories, $scopes): array {
            $item = is_array($item) ? $item : [];
            $scope = $this->normalizeString($item['scope'] ?? null);
            $scope = in_array($scope, $scopes, true) ? $scope : 'unknown';
            $description = $this->normalizeString($item['description'] ?? null) ?? '';

            return [
                'category' => $this->normalizeCategory($item['category'] ?? null, $categories),
                'control_item' => $this->normalizeString($item['control_item'] ?? null),
                'description' => $description,
                'scope' => $scope,
                'area_note' => $this->normalizeString($item['area_note'] ?? null),
                'equipment_codes' => array_values(array_filter(array_map(
                    fn ($c) => $this->normalizeString(is_string($c) ? $c : null),
                    is_array($item['equipment_codes'] ?? null) ? $item['equipment_codes'] : []
                ))),
                'is_uncertain' => $description === '',
            ];
        }, $rawFindings));
    }
}
