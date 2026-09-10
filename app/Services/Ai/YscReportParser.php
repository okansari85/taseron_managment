<?php

namespace App\Services\Ai;

use DateTime;

// YSC DOCUMENT PARSER — kullanıcının istediği ayrım burada somutlaşıyor:
//
//   Nemotron OCR v2 (veya başka bir sağlayıcı)
//           ↓  (ham metin)
//   PdfTextExtractor
//           ↓  (ham metin)
//   YscReportParser   ← BU SINIF, bizim kodumuz
//           ↓  (doğrulanmış, normalize edilmiş, belirsizleri işaretlenmiş veri)
//   Matching Engine (MatchingEngine + YscMatchingProfile)
//           ↓
//   YSC Inventory
//
// AI modeli (NvidiaNimClient) SADECE ham bir tahmin üretir — tarih formatı,
// sonuç ifadesi, boş/anlamsız alan olabilir. Bu sınıf o tahmine KÖRÜ KÖRÜNE
// güvenmez: her alanı normalize eder (tarih → Y-m-d, sonuç → uygun/
// uygun_degil/null), hiçbir eşleştirme yolu (ne kod ne tip+kapasite+konum)
// bulunamayan ekipmanı is_uncertain=true ile işaretler. Matching Engine
// sadece bu sınıfın ÇIKTISINI görür — OCR sağlayıcısı yarın değişse bile
// Matching hiç haberdar olmaz. serial_no SADECE veri alanı olarak taşınır,
// YscMatchingProfile hiçbir aşamada ona bakmaz (YSC'nin kimliği koddur).
class YscReportParser
{
    public function __construct(
        private NvidiaNimClient $ai,
    ) {
    }

    // $pages: PdfTextExtractor::extractPages() çıktısı — FireSuppressionReportParser
    // ile aynı sebeple (NVIDIA NIM ücretsiz katmanında büyük tek seferlik
    // çağrılar 502/503/504 ile kesilebiliyor) sayfa sayfa işlenip birleştirilir.
    public function parse(array $pages): array
    {
        $prompt = $this->buildPrompt();
        $guesses = array_map(
            fn (string $pageText) => $this->ai->extractStructuredJson($prompt, $pageText),
            $pages
        );
        $guess = $this->mergeGuesses($guesses);

        return [
            'control_date' => $this->normalizeDate($guess['control_date'] ?? null),
            'next_control_date' => $this->normalizeDate($guess['next_control_date'] ?? null),
            'result' => $this->normalizeResult($guess['result'] ?? null),
            'company_name' => $this->normalizeString($guess['company_name'] ?? null),
            'equipment' => $this->normalizeEquipment(is_array($guess['equipment'] ?? null) ? $guess['equipment'] : []),
        ];
    }

    private function mergeGuesses(array $guesses): array
    {
        $merged = ['control_date' => null, 'next_control_date' => null, 'result' => null, 'company_name' => null, 'equipment' => []];
        $equipmentByCode = [];
        $equipmentWithoutCode = [];

        foreach ($guesses as $guess) {
            if (! is_array($guess)) {
                continue;
            }

            foreach (['control_date', 'next_control_date', 'result', 'company_name'] as $field) {
                if (! empty($guess[$field])) {
                    $merged[$field] = $guess[$field];
                }
            }

            foreach (is_array($guess['equipment'] ?? null) ? $guess['equipment'] : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $code = is_string($item['code'] ?? null) ? mb_strtolower(trim($item['code']), 'UTF-8') : '';

                if ($code === '') {
                    $equipmentWithoutCode[] = $item;

                    continue;
                }

                if (! isset($equipmentByCode[$code])) {
                    $equipmentByCode[$code] = $item;

                    continue;
                }

                foreach ($item as $key => $value) {
                    if (empty($equipmentByCode[$code][$key]) && ! empty($value)) {
                        $equipmentByCode[$code][$key] = $value;
                    }
                }
            }
        }

        $merged['equipment'] = [...array_values($equipmentByCode), ...$equipmentWithoutCode];

        return $merged;
    }

    // AI'dan sadece HAM veri istiyoruz — normalizasyon kuralları (tarih
    // formatı, sonuç kelimesi eşleme) kasıtlı olarak PHP tarafında, aşağıda.
    // Böylece "AI ne dönerse o" değil, deterministik/denetlenebilir bir
    // kural seti geçerli olur.
    private function buildPrompt(): string
    {
        return <<<PROMPT
Sen bir YSC (taşınabilir yangın söndürme cihazı) yıllık periyodik kontrol raporundan HAM veri çıkaran bir asistansın.
Aşağıdaki metinden şu JSON şemasına göre veri çıkar, SADECE JSON döndür:
{
  "control_date": "metinde geçen kontrol/muayene tarihi, aynen metindeki gibi",
  "next_control_date": "metinde geçen sonraki kontrol tarihi, aynen metindeki gibi",
  "result": "metinde geçen genel sonuç ifadesi, aynen metindeki gibi",
  "company_name": "kontrolü yapan firma adı",
  "equipment": [{"code": "YSC/ekipman kodu", "equipment_type": "cihaz tipi (örn. ABC Kuru Kimyevi Toz, CO2)", "capacity": "kapasite (örn. 6 KG)", "serial_no": "seri no", "location_note": "konum", "result": "bu cihaz için sonuç ifadesi", "note": "varsa not"}]
}
Tarihi veya sonuç ifadesini normalize etmeye ÇALIŞMA — metinde ne yazıyorsa onu aynen döndür, bu işi başka bir katman yapacak.
Metinde açıkça olmayan bilgiyi ASLA uydurma, null bırak.
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

    // mb_strtolower('UTF-8') Türkçe büyük "İ"yi Unicode kurallarına göre
    // "i" + birleşen nokta işaretine çevirir (iki kod noktası), düz "i" değil
    // — bu yüzden "DEĞİLDİR" gibi kelimeler mb_strtolower sonrası ASCII "i"
    // içeren aranan alt dizeyle (örn. "değil") EŞLEŞMEZ. Türkçe büyük
    // İ/I harflerini küçültmeden önce elle normalize ediyoruz (bkz.
    // FireSuppressionReportParser::toLowerTr() — aynı hata orada bulundu).
    private function toLowerTr(string $value): string
    {
        $value = str_replace(['İ', 'I'], ['i', 'ı'], $value);

        return mb_strtolower($value, 'UTF-8');
    }

    private function normalizeResult(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = $this->toLowerTr(trim($value));

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

    private function normalizeEquipment(array $rawEquipment): array
    {
        return array_values(array_map(function ($item): array {
            $item = is_array($item) ? $item : [];
            $code = $this->normalizeString($item['code'] ?? null);
            $equipmentType = $this->normalizeString($item['equipment_type'] ?? null);
            $capacity = $this->normalizeString($item['capacity'] ?? null);
            $locationNote = $this->normalizeString($item['location_note'] ?? null);

            return [
                'code' => $code,
                'equipment_type' => $equipmentType,
                'capacity' => $capacity,
                'serial_no' => $this->normalizeString($item['serial_no'] ?? null),
                'location_note' => $locationNote,
                'result' => $this->normalizeResult($item['result'] ?? null),
                'note' => $this->normalizeString($item['note'] ?? null),
                // Matching Engine'in ne kesin (kod) ne de aday (tip+kapasite+
                // konum) araması için hiçbir dayanağı yoksa bu satır kullanıcıya
                // "belirsiz" olarak ayrıca gösterilmeli.
                'is_uncertain' => $code === null && $equipmentType === null && $capacity === null && $locationNote === null,
            ];
        }, $rawEquipment));
    }
}
