<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Format-independent fire-suppression report analysis.
 *
 * The PDF text is sent to Gemini in ONE request. The model understands the
 * report structure; backend matching remains deterministic and happens after
 * extraction.
 */
class FireSuppressionAiReportAnalyzer
{
    public function __construct(private GeminiClient $ai)
    {
    }

    public function analyze(array $pages): array
    {
        $text = $this->buildDocumentText($pages);

        if (trim($text) === '') {
            throw new RuntimeException('PDF metni boş olduğu için rapor analiz edilemedi.');
        }

        $result = $this->ai->extractStructuredJson($this->systemPrompt(), $text, 50000);
        $normalized = $this->normalize($result);

        // GEÇİCİ DEBUG: Ham Gemini çıktısını normalize edilmiş sonuçtan
        // ayrı bir üst seviye alan olarak taşı. Böylece frontend Console'da
        // ham AI çıktısı ile normalize edilmiş sonucu doğrudan karşılaştırabiliriz.
        $normalized['debug_ai_raw'] = $result;

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

            $parts[] = '--- SAYFA ' . ($index + 1) . " ---\n" . $page;
        }

        return implode("\n\n", $parts);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Sen yangın tesisatı periyodik kontrol raporlarını anlayan bir veri çıkarma motorusun.

GÖREV:
Sana biçimi önceden bilinmeyen bir yangın tesisatı/periyodik kontrol PDF'sinin tamamının metni verilecek. Rapordaki başlıkları, tabloları, numaralandırmayı ve terimleri kendin yorumla. Firma şablonuna veya sabit bölüm sırasına güvenme.

AMAÇ:
Tek ve KOMPAKT bir JSON üret. JSON daha sonra backend tarafından mevcut tesisat envanteriyle eşleştirilecek ve kullanıcıya onaylatılacak. Veritabanına kayıt veya eşleştirme yapma.

ÇIKAR:
1. Rapor üst bilgileri.
2. Raporda gerçekten kontrol edilen sistemler/gruplar.
3. Her sistem altında raporda gerçekten listelenen fiziksel bileşenler.
4. Her sistem için toplam kontrol sayısı ve uygunsuz kontrol sayısı.
5. Uygunsuzluk/bulguları sistem bazında, ayrıntılı ve yalnızca bir kez.

KOMPAKTLIK KURALI:
- Bileşen başına kontrol maddesi ÇIKARMA.
- Bileşen başına U/OK/UYGUN sonuçlarını ÇIKARMA.
- Bileşen başına bulgu tekrarlama.
- Kontrol maddesi başlıklarını, kodlarını veya açıklamalarını component içine yazma.
- Bir sistemin bulgularını findings altında sistem bazında yaz; aynı bulguyu farklı bileşenlere tekrar etme.
- findings içinde component_code, control_item, scope veya area_note üretme.
- Bulguyu raporda nasıl ayrıntılı açıklanmışsa anlamını kaybetmeden tek açıklama olarak aktar.
- Ama fiziksel bileşen listesinde her gerçek bileşeni koru; eşleştirme için code/name/location/brand/model/serial_no bilgilerini mümkün olduğunca çıkar.
- Bir bilgi raporda yoksa null kullan. Tahmin etme.

DOMAIN HİYERARŞİSİ:
- Yangın Tesisatı ana tesisattır.
- Sistem, tesisatın altındaki gerçek sistem/gruptur. Örn. Yangın Dolapları, Yangın Pompa Dairesi, Sprinkler, Hidrant.
- Bileşen, sistemin fiziksel tekil unsurudur. Örn. YD1, YD2, Pompa 1, Pompa 2, Jokey Pompa, Hidrant 01.
- Rapor başlıklarını yapısal bağlama göre sistem olarak yorumla.
- Vana, manometre, presostat vb. kendi başına sistem değildir; rapor bunları ayrı bir sistem/kontrol grubu olarak tanımlamıyorsa ilgili sistem içinde değerlendir.
- Sprinkler sistemi tek tek sprinkler başlıkları vermiyorsa yapay sprinkler bileşenleri üretme.
- Raporda olmayan fiziksel bileşeni kesinlikle uydurma.
- Bulgu metninde geçen bir kodu, fiziksel ekipman listesiyle doğrulamıyorsan bileşen listesine ekleme.
- Aynı bileşeni farklı sayfalarda tekrar gördüğünde tek kayıtta birleştir.

SAYIM:
- control_count = raporda o sistem için kontrol edilmiş toplam kontrol maddesi sayısı.
- nonconforming_count = raporda o sistem için uygunsuz/UD olarak işaretlenen kontrol maddesi sayısı.
- U sonuçlarını listeleme; yalnızca bu iki sistem toplamını ver.
- Sayıları rapordaki gerçek kontrol matrisinden/tablosundan çıkar. Emin değilsen 0 yazmak yerine raporun desteklediği sayıyı kullan; desteklenemiyorsa 0 kullan.

KATEGORİLER:
- yangin_dolabi
- yangin_pompasi
- hidrant
- sprinkler
- su_alma_verme
- su_deposu
- sabit_boru_tesisati
- gazli_sondurme
- diger

KATEGORİDEN emin değilsen diger kullan; sistem name alanında rapordaki adı koru.

BİLEŞEN KİMLİĞİ:
Her gerçek fiziksel bileşen için yalnızca şu alanları çıkar:
- code
- name
- location
- brand
- model
- serial_no

Örneğin YD19 raporda geçiyorsa YD19'u mutlaka ayrı bileşen olarak çıkar. Konumu, markası, modeli veya seri numarası raporda yoksa null bırak.

BULGULAR:
findings AYRI bir array olmalıdır. Her kayıt yalnızca:
- system_name
- description
alanlarından oluşur.

Bir sistem için birden fazla farklı bulgu varsa ayrı kayıtlar olabilir. Aynı bulguyu component bazında çoğaltma. Bulgular, sistemin neden uygun olmadığını anlayacak kadar ayrıntılı olmalıdır.

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
      "category": "kategori",
      "control_count": 0,
      "nonconforming_count": 0,
      "components": [
        {
          "code": "string veya null",
          "name": "string veya null",
          "location": "string veya null",
          "brand": "string veya null",
          "model": "string veya null",
          "serial_no": "string veya null"
        }
      ]
    }
  ],
  "findings": [
    {
      "system_name": "string veya null",
      "description": "ayrıntılı bulgu"
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
        $normalizedSystems = [];

        foreach ($systems as $system) {
            if (!is_array($system)) {
                continue;
            }

            $category = $this->normalizeCategory($system['category'] ?? null);
            $systemName = $this->stringOrNull($system['name'] ?? null);
            $components = is_array($system['components'] ?? null) ? $system['components'] : [];

            $normalizedComponents = [];

            foreach ($components as $component) {
                if (!is_array($component)) {
                    continue;
                }

                $code = $this->stringOrNull($component['code'] ?? null);
                $name = $this->stringOrNull($component['name'] ?? null);
                $location = $this->stringOrNull($component['location'] ?? null);
                $brand = $this->stringOrNull($component['brand'] ?? null);
                $model = $this->stringOrNull($component['model'] ?? null);
                $serial = $this->stringOrNull($component['serial_no'] ?? null);

                if ($code === null && $name === null) {
                    continue;
                }

                $normalizedComponents[] = [
                    'code' => $code,
                    'name' => $name,
                    'location' => $location,
                    'brand' => $brand,
                    'model' => $model,
                    'serial_no' => $serial,
                ];

                $equipment[] = [
                    'code' => $code ?? $name,
                    'category' => $category,
                    'system_name' => $systemName,
                    'system_category' => $category,
                    'location_note' => $location,
                    'brand' => $brand,
                    'model' => $model,
                    'serial_no' => $serial,
                    'result' => ((int) ($system['nonconforming_count'] ?? 0)) > 0 ? 'uygun_degil' : 'uygun',
                    'note' => null,
                    'control_items' => [],
                ];
            }

            $normalizedSystems[] = [
                'name' => $systemName,
                'category' => $category,
                'control_count' => max(0, (int) ($system['control_count'] ?? 0)),
                'nonconforming_count' => max(0, (int) ($system['nonconforming_count'] ?? 0)),
                'components' => $normalizedComponents,
            ];
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
            'systems' => $normalizedSystems,
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

            $normalized[] = [
                'category' => 'diger',
                'control_item' => null,
                'description' => trim((string) $finding['description']),
                'scope' => 'unknown',
                'area_note' => $this->stringOrNull($finding['system_name'] ?? null),
                'system_name' => $this->stringOrNull($finding['system_name'] ?? null),
                'equipment_codes' => [],
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

        return null;
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
            default => 'diger',
        };
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (!filled($value)) {
            return null;
        }

        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
