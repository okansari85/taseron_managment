<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Format-independent fire-suppression report analysis.
 *
 * The PDF text is sent to NVIDIA NIM in ONE request. The model is responsible
 * for understanding the report's own headings/table layout; backend matching
 * remains deterministic and is performed after this service returns.
 */
class FireSuppressionAiReportAnalyzer
{
    public function __construct(private NvidiaNimClient $ai)
    {
    }

    public function analyze(array $pages): array
    {
        $text = $this->buildDocumentText($pages);

        if (trim($text) === '') {
            throw new RuntimeException('PDF metni boş olduğu için rapor analiz edilemedi.');
        }

        $result = $this->ai->extractStructuredJson($this->systemPrompt(), $text, 24000);
        $normalized = $this->normalize($result);

        // Geçici debug alanı: mevcut normalize edilmiş sözleşmeyi bozmadan
        // NVIDIA NIM'in ham JSON çıktısını frontend'e ulaştırır.
        $normalized['ai_raw_result'] = $result;

        return $normalized;
    }

    private function buildDocumentText(array $pages): string
    {
        $parts = [];

        foreach (array_values($pages) as $index => $page) {
            $page = trim((string) $page);
            if ($page === '') {
                continue;
            }

            $parts[] = '--- SAYFA ' . ($index + 1) . ' ---\n' . $page;
        }

        return implode("\n\n", $parts);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Sen yangın tesisatı periyodik kontrol raporlarını anlayan bir veri çıkarma motorusun.

GÖREV:
Sana, biçimi önceden bilinmeyen bir yangın tesisatı/periyodik kontrol PDF'sinin tamamının metni verilecek. Raporda kullanılan başlıkları, tablo düzenini, numaralandırmayı ve terimleri kendin anlamlandır. Önceden tanımlı bölüm başlıklarına veya belirli bir firma şablonuna güvenme.

AMAÇ:
Tek bir JSON üret. JSON daha sonra backend tarafından mevcut tesisat envanteriyle eşleştirilecek ve kullanıcıya onaylatılacak. Sen hiçbir veriyi veritabanına kaydetmiyorsun ve eşleştirme yapmıyorsun.

ÇIKARILACAK VERİLER:
1. Rapor üst bilgileri: kontrol tarihi, sonraki kontrol tarihi, rapor numarası mümkünse, kontrolü yapan firma, genel sonuç.
2. Raporda gerçekten kontrol edilen ana sistemler/kategoriler.
3. Her sistemin raporda gerçekten listelenen bileşenleri/ekipmanları.
4. Her bileşen için raporda bulunan kontrol maddeleri ve sonuçları.
5. Uygunsuzluk/bulgu açıklamaları ve mümkünse ilgili sistem/bileşen/kontrol maddesi.

DOMAIN HİYERARŞİSİ:
- Yangın Tesisatı ana tesisattır.
- Sistem, tesisatın altındaki gerçek sistem/gruptur (ör. Yangın Dolapları, Yangın Pompa Dairesi, Sprinkler, Hidrant).
- Bileşen, sistemin fiziksel tekil unsurudur (ör. YD-01, YD-02, Pompa 1, Pompa 2, Jokey Pompa, Hidrant 01).
- Bir rapor başlığını sistem olarak kabul etmek için rapordaki yapısal bağlamı kullan.
- Vana, manometre, presostat vb. ifadeleri kendi başına sistem yapma; rapor bunları ayrı bir kontrol grubu olarak tanımlamıyorsa ilgili sistemin kontrol içeriği olarak değerlendir.
- Sprinkler sistemi gibi bir sistem, raporda tek tek sprinkler başlıkları verilmemişse yapay sprinkler bileşenleri üretme.

ÇOK ÖNEMLİ KURALLAR:
- Raporda olmayan ekipman/bileşen KESİNLİKLE uydurma.
- Genel bilgi bölümündeki seri numarası, tesisat numarası veya örnek kodu tek başına ekipman değildir; gerçek ekipman tablosu/listesi bağlamı gerekir.
- Bulgu metninde adı geçen bir kodu, ekipman tablosunda fiziksel bileşen olarak doğrulamıyorsan equipment listesine ekleme.
- Aynı bileşeni farklı sayfalarda tekrar gördüğünde tek kayıtta birleştir.
- Rapordaki kontrol maddelerinin kendi kodlarını ve başlıklarını mümkün olduğunca aynen koru.
- Uygunluk sonuçlarını yalnızca açıkça raporda verilen sonuca göre çıkar.
- U/OK/UYGUN gibi olumlu işaretleri "uygun"; UD/UYGUN DEĞİL/uygunsuz gibi açık olumsuz işaretleri "uygun_degil"; N/N/A/uygulanamaz gibi değerlendirme dışı işaretleri "uygulanamiyor" olarak normalize et.
- Sonuç açık değilse tahmin etme; ilgili alanı null bırak.
- Bir kontrol maddesi belirli bir bileşene uygulanmadıysa o bileşene ekleme.
- Bulgu açıklamasını kontrol maddesiyle ilişkilendirebiliyorsan control_item alanını doldur.
- Bulgunun tüm sisteme/bileşenlere ait olduğu açıkça belirtiliyorsa scope=all; tek bileşen ise specific; yalnızca alan/bölge ise area; emin değilsen unknown.
- Sayım üretirken yalnızca raporda gerçekten listelenen fiziksel bileşenleri say.

KATEGORİLER:
Kategori alanında mümkün olduğunda şu değerlerden birini kullan:
- yangin_dolabi
- yangin_pompasi
- hidrant
- sprinkler
- su_alma_verme
- su_deposu
- sabit_boru_tesisati
- gazli_sondurme
- diger

Bunlardan hangisinin doğru olduğundan emin değilsen "diger" kullan; ancak rapordaki sistem adını name alanında aynen koru.

JSON ŞEMASI:
{
  "report": {
    "control_date": "YYYY-MM-DD veya null",
    "next_control_date": "YYYY-MM-DD veya null",
    "report_no": "string veya null",
    "company_name": "string veya null",
    "overall_result": "uygun | uygun_degil | null"
  },
  "systems": [
    {
      "name": "rapordaki sistem adı",
      "category": "yukarıdaki kategori veya diger",
      "description": "sistemin rapordaki kısa tanımı veya null",
      "components": [
        {
          "code": "YD-01 gibi rapordaki gerçek kod veya null",
          "name": "kod yoksa rapordaki bileşen adı veya null",
          "location_note": "string veya null",
          "brand": "string veya null",
          "model": "string veya null",
          "serial_no": "string veya null",
          "result": "uygun | uygun_degil | null",
          "note": "string veya null",
          "control_items": [
            {
              "code": "5.47 veya rapordaki kod veya null",
              "title": "kontrol maddesi başlığı",
              "status": "uygun | uygun_degil | uygulanamiyor",
              "description": "rapordaki açıklama veya null"
            }
          ]
        }
      ]
    }
  ],
  "findings": [
    {
      "category": "kategori veya null",
      "system_name": "rapordaki sistem adı veya null",
      "component_code": "rapordaki bileşen kodu veya null",
      "control_item": "kontrol maddesi kodu/adı veya null",
      "description": "bulgu açıklaması",
      "scope": "all | specific | area | unknown",
      "area_note": "string veya null"
    }
  ]
}

SADECE geçerli JSON döndür. Markdown, açıklama, kod bloğu veya JSON dışı metin döndürme.
PROMPT;
    }

    private function normalize(array $result): array
    {
        $report = is_array($result['report'] ?? null) ? $result['report'] : [];
        $systems = is_array($result['systems'] ?? null) ? $result['systems'] : [];
        $findings = is_array($result['findings'] ?? null) ? $result['findings'] : [];

        $equipment = [];

        foreach ($systems as $system) {
            if (!is_array($system)) {
                continue;
            }

            $category = $this->normalizeCategory($system['category'] ?? null);
            $components = is_array($system['components'] ?? null) ? $system['components'] : [];

            foreach ($components as $component) {
                if (!is_array($component)) {
                    continue;
                }

                $code = $this->stringOrNull($component['code'] ?? null);
                $name = $this->stringOrNull($component['name'] ?? null);
                $location = $this->stringOrNull($component['location_note'] ?? null);
                $brand = $this->stringOrNull($component['brand'] ?? null);
                $model = $this->stringOrNull($component['model'] ?? null);
                $serial = $this->stringOrNull($component['serial_no'] ?? null);
                $controlItems = [];

                foreach ((array) ($component['control_items'] ?? []) as $controlItem) {
                    if (!is_array($controlItem) || !filled($controlItem['title'] ?? null)) {
                        continue;
                    }

                    $status = $this->normalizeStatus($controlItem['status'] ?? null);
                    if ($status === null) {
                        continue;
                    }

                    $controlItems[] = [
                        'code' => $this->stringOrNull($controlItem['code'] ?? null),
                        'title' => trim((string) $controlItem['title']),
                        'status' => $status,
                        'description' => $this->stringOrNull($controlItem['description'] ?? null),
                    ];
                }

                // Components are intentionally kept only when the AI supplied
                // a real identifying name/code. Never create phantom rows.
                if ($code === null && $name === null) {
                    continue;
                }

                $equipment[] = [
                    'code' => $code ?? $name,
                    'category' => $category,
                    'location_note' => $location,
                    'brand' => $brand,
                    'model' => $model,
                    'serial_no' => $serial,
                    'result' => $this->normalizeResult($component['result'] ?? null, $controlItems),
                    'note' => $this->stringOrNull($component['note'] ?? null),
                    'control_items' => $controlItems,
                ];
            }
        }

        return [
            'control_date' => $this->dateOrNull($report['control_date'] ?? null),
            'next_control_date' => $this->dateOrNull($report['next_control_date'] ?? null),
            'overall_result' => $this->normalizeResult($report['overall_result'] ?? null),
            'company_name' => $this->stringOrNull($report['company_name'] ?? null),
            'covered_categories' => array_values(array_unique(array_filter(array_map(
                fn ($system) => is_array($system) ? $this->normalizeCategory($system['category'] ?? null) : null,
                $systems
            )))),
            'equipment' => $equipment,
            'findings' => $this->normalizeFindings($findings),
        ];
    }

    private function normalizeFindings(array $findings): array
    {
        $normalized = [];

        foreach ($findings as $finding) {
            if (!is_array($finding) || !filled($finding['description'] ?? null)) {
                continue;
            }

            $scope = strtolower(trim((string) ($finding['scope'] ?? 'unknown')));
            if (!in_array($scope, ['all', 'specific', 'area', 'unknown'], true)) {
                $scope = 'unknown';
            }

            $normalized[] = [
                'category' => $this->normalizeCategory($finding['category'] ?? null),
                'control_item' => $this->stringOrNull($finding['control_item'] ?? null),
                'description' => trim((string) $finding['description']),
                'scope' => $scope,
                'area_note' => $this->stringOrNull($finding['area_note'] ?? null),
                'equipment_codes' => array_values(array_filter(array_map(
                    fn ($code) => $this->stringOrNull($code),
                    [$finding['component_code'] ?? null]
                ))),
            ];
        }

        return $normalized;
    }

    private function normalizeStatus(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        $value = str_replace(['ı', 'İ'], ['i', 'i'], $value);

        return match (true) {
            in_array($value, ['uygun', 'u', 'ok', 'okey', 'gecerli', 'geçerli'], true) => 'uygun',
            in_array($value, ['uygun_degil', 'uygun değil', 'uygunsuz', 'ud', 'noktasi', 'nok'], true) => 'uygun_degil',
            in_array($value, ['uygulanamiyor', 'uygulanamıyor', 'n/a', 'na', 'n', '-'], true) => 'uygulanamiyor',
            default => null,
        };
    }

    private function normalizeResult(mixed $value, array $controlItems = []): ?string
    {
        $value = $this->normalizeStatus($value);
        if ($value !== null && $value !== 'uygulanamiyor') {
            return $value;
        }

        foreach ($controlItems as $item) {
            if (($item['status'] ?? null) === 'uygun_degil') {
                return 'uygun_degil';
            }
        }

        return $value === 'uygulanamiyor' ? null : null;
    }

    private function normalizeCategory(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        $value = str_replace(['ı', 'İ'], ['i', 'i'], $value);

        return match (true) {
            str_contains($value, 'dolab') => 'yangin_dolabi',
            str_contains($value, 'pompa') => 'yangin_pompasi',
            str_contains($value, 'hidrant') => 'hidrant',
            str_contains($value, 'sprink') || str_contains($value, 'yağmurlama') || str_contains($value, 'yagmurlama') => 'sprinkler',
            str_contains($value, 'alma') || str_contains($value, 'verme') => 'su_alma_verme',
            str_contains($value, 'depo') => 'su_deposu',
            str_contains($value, 'sabit boru') || str_contains($value, 'kolekt') || str_contains($value, 'vana') => 'sabit_boru_tesisati',
            str_contains($value, 'gaz') => 'gazli_sondurme',
            in_array($value, ['yangin_dolabi','yangin_pompasi','hidrant','sprinkler','su_alma_verme','su_deposu','sabit_boru_tesisati','gazli_sondurme','diger'], true) => $value,
            default => 'diger',
        };
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }
}
