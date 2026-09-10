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

    // $pages: PdfTextExtractor::extractPages() çıktısı — tüm metni TEK bir AI
    // çağrısına vermek yerine SAYFA SAYFA işlenir ve sonuçlar birleştirilir.
    // Sebep: NVIDIA NIM'in ücretsiz katmanındaki ağ geçidi, çok sayfalı/yoğun
    // raporlarda tek seferde büyük bir JSON üretilmeye çalışılınca
    // 502/503/504 ile kesebiliyor; küçük sayfa parçaları hem daha hızlı
    // döner hem de rapor uzunluğundan bağımsız ölçeklenir.
    public function parse(array $pages): array
    {
        $categories = FireSuppressionInventoryItem::CATEGORIES;
        $prompt = $this->buildPrompt($categories);

        $guesses = array_map(
            fn (string $pageText) => $this->ai->extractStructuredJson($prompt, $pageText),
            $pages
        );

        $guess = $this->mergeGuesses($guesses);

        return [
            'control_date' => $this->normalizeDate($guess['control_date'] ?? null),
            'next_control_date' => $this->normalizeDate($guess['next_control_date'] ?? null),
            'overall_result' => $this->normalizeResult($guess['overall_result'] ?? null),
            'company_name' => $this->normalizeString($guess['company_name'] ?? null),
            'covered_categories' => $this->normalizeCategories(is_array($guess['covered_categories'] ?? null) ? $guess['covered_categories'] : [], $categories),
            'equipment' => $this->normalizeEquipment(is_array($guess['equipment'] ?? null) ? $guess['equipment'] : [], $categories),
            'findings' => $this->normalizeFindings(is_array($guess['findings'] ?? null) ? $guess['findings'] : [], $categories),
        ];
    }

    // Her sayfanın ham AI tahminini tek bir ham tahmine birleştirir —
    // normalize* metotları değişmeden, tek bir birleşik sonuç üzerinde
    // çalışmaya devam eder.
    private function mergeGuesses(array $guesses): array
    {
        $merged = ['control_date' => null, 'next_control_date' => null, 'overall_result' => null, 'company_name' => null, 'covered_categories' => [], 'equipment' => [], 'findings' => []];
        $equipmentByCode = [];
        $equipmentWithoutCode = [];

        foreach ($guesses as $guess) {
            if (! is_array($guess)) {
                continue;
            }

            foreach (['control_date', 'next_control_date', 'overall_result', 'company_name'] as $field) {
                if (! empty($guess[$field])) {
                    $merged[$field] = $guess[$field];
                }
            }

            if (is_array($guess['covered_categories'] ?? null)) {
                $merged['covered_categories'] = [...$merged['covered_categories'], ...$guess['covered_categories']];
            }

            if (is_array($guess['findings'] ?? null)) {
                $merged['findings'] = [...$merged['findings'], ...$guess['findings']];
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

                // Aynı kod birden fazla sayfada geçiyorsa (örn. ekipman
                // tablosu bir sayfada, tespit/bulgu notu başka bir sayfada) —
                // dolu alanları koru, boş olanları yeni sayfadan tamamla.
                foreach ($item as $key => $value) {
                    if ($key === 'has_nonconformity') {
                        $equipmentByCode[$code]['has_nonconformity'] = ($equipmentByCode[$code]['has_nonconformity'] ?? false) || ($value ?? false);

                        continue;
                    }

                    if (empty($equipmentByCode[$code][$key]) && ! empty($value)) {
                        $equipmentByCode[$code][$key] = $value;
                    }
                }
            }
        }

        $merged['equipment'] = [...array_values($equipmentByCode), ...$equipmentWithoutCode];

        return $merged;
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
  "next_control_date": "metinde geçen sonraki kontrol / azami geçerlilik / gelecek muayene tarihi, aynen metindeki gibi",
  "overall_result": "raporun EN SONUNDAKİ 'SONUÇ VE KANAAT' (veya benzeri başlıklı) bölümünde yazan nihai ifade, aynen metindeki gibi",
  "company_name": "kontrolü/muayeneyi yapan akredite/yetkili firmanın unvanı (rapor başlığı/logosu, alt bilgi veya 'muayene kuruluşu' alanında geçer) — kontrol edilen TESİSİN/MÜŞTERİNİN unvanı DEĞİL",
  "covered_categories": ["kategori_kodu", ...],
  "equipment": [{"code": "string", "category": "kategori_kodu", "location_note": "string", "brand": "marka", "model": "model", "serial_no": "seri no", "has_nonconformity": true|false, "note": "bu ekipmana özel tespit/bulgu açıklaması (varsa)"}],
  "findings": [{"category": "kategori_kodu", "control_item": "string", "description": "string", "scope": "specific"|"area"|"unknown", "area_note": "string", "equipment_codes": ["string", ...]}]
}

ÖNEMLİ — Ekipmanlar tablo halinde, SÜTUN olarak listelenmiş olabilir (örn. "YD1 YD2 YD3 ... YD20" başlıkları bir satırda, altında marka/uzunluk/kontrol maddesi sonuçları (U/UD/N) her sütun için ayrı ayrı verilir). Bu durumda TABLODAKİ HER SÜTUNU (her ekipman kodunu) AYRI bir "equipment" kaydı olarak çıkar — 5, 10, 20, hatta daha fazla ekipman olabilir, HİÇBİRİNİ ATLAMA.
AYNI KURAL POMPALAR İÇİN DE GEÇERLİ — "Pompa No 1 2 3 Jokey" gibi bir başlık satırından sonra Marka/Seri No/Yakıt/Güç/Debi/Basınç gibi satırlar geliyorsa, HER POMPA SÜTUNU (boş/"-" olanlar hariç) AYRI bir "equipment" kaydıdır, category="yangin_pompasi", code alanına "Pompa 1", "Pompa 2" gibi sütun başlığından türettiğin bir isim yaz (rapor açık bir kod vermiyorsa bile), brand=Marka satırındaki değer, serial_no=Seri No satırındaki değer. Örnek:
  Girdi:
    Pompa No       1        2        3    Jokey
    Marka          MAS      MAS      -    -
    Seri No        A1205075 A1205075 -    -
  Çıktı equipment kayıtları: {"code":"Pompa 1","category":"yangin_pompasi","brand":"MAS","serial_no":"A1205075",...} ve {"code":"Pompa 2","category":"yangin_pompasi","brand":"MAS","serial_no":"A1205075",...} — "3" ve "Jokey" sütunları ATLANIR çünkü o sütundaki TÜM satırlar (Marka, Seri No, Yakıt, Güç, Debi, Basınç) "-" değerinde; bu, o cihazın kurulu OLMADIĞI anlamına gelir, "değerlendirme dışı jokey pompa" gibi bir yorum UYDURMA. KURAL: bir sütunda Marka/Seri No dahil TÜM satır değerleri "-" veya boşsa, o sütun için HİÇ equipment kaydı oluşturma.
"equipment[].has_nonconformity": SAYIM YAPMA, SADECE VAR/YOK kontrolü yap — o ekipmanın sütunundaki kontrol maddesi satırlarını (U/UD/N) tek tek tara, İÇLERİNDE EN AZ BİR TANE "UD" (Uygun Değil) işareti VARSA true yaz; hiç "UD" yoksa (hepsi "U" veya "N" ise) false yaz. Kaç tane UD olduğunu SAYMANA gerek yok, sadece "en az bir tane var mı" sorusuna cevap ver — bu çok daha kolay ve hataya kapalıdır.
"equipment[].note": Raporun "TESPİT VE BULGULAR"/"6. TESPİT VE BULGULAR" gibi bir bölümünde bu ekipmanın kodu açıkça geçiyorsa (örn. "YD14 ve YD15 nolu yangın dolaplarında hasar var düzeltilmelidir"), o cümleyi/açıklamayı bu ekipmanın "note" alanına yaz.
"findings[].equipment_codes": TESPİT VE BULGULAR bölümündeki her madde için, o maddede adı geçen TÜM ekipman kodlarını listele — bazen tek tek ("YD19"), bazen virgülle ayrılmış uzun bir liste halinde geçebilir ("YD1, YD2, YD3, YD4, YD14, ..."), hiçbirini atlama. Madde birden fazla ekipmandan bahsediyorsa hepsini equipment_codes dizisine ekle. Bu dizide SADECE equipment listesindeki gerçek "code" değerleri olur — asla kategori adı ("yangin_pompasi" gibi) yazma; ilgili ekipmanın kodu belli değilse equipment_codes'u boş bırak.
overall_result için: satır içindeki "U"/"UD" kısaltmalarıyla KARIŞTIRMA — sadece raporun nihai SONUÇ/KANAAT cümlesini kullan.
Tarihi veya sonuç ifadelerini normalize etmeye ÇALIŞMA — metinde ne yazıyorsa onu aynen döndür, bu işi başka bir katman yapacak.
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

    // mb_strtolower('UTF-8') Türkçe büyük "İ"yi Unicode kurallarına göre
    // "i" + birleşen nokta işaretine çevirir (iki kod noktası), düz "i" değil
    // — bu yüzden "DEĞİLDİR" gibi kelimeler mb_strtolower sonrası ASCII "i"
    // içeren aranan alt dizeyle (örn. "değil") EŞLEŞMEZ. Türkçe büyük
    // İ/I harflerini küçültmeden önce elle normalize ediyoruz.
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

    private function resultFromHasNonconformity(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'uygun_degil' : 'uygun';
        }

        if (is_string($value)) {
            $normalized = $this->toLowerTr(trim($value));
            if (in_array($normalized, ['true', 'evet', 'yes', '1'], true)) {
                return 'uygun_degil';
            }
            if (in_array($normalized, ['false', 'hayır', 'hayir', 'no', '0'], true)) {
                return 'uygun';
            }
        }

        return null;
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
                // AI'dan çoğunluk hesabı isteyen bir "result" cümlesi yerine
                // basit bir var/yok sorusu (has_nonconformity) istiyoruz —
                // "uygun"/"uygun_degil" kararı burada, deterministik olarak
                // veriliyor (AI'ın onlarca satırı sayıp özetlemesi yerine).
                'result' => $this->resultFromHasNonconformity($item['has_nonconformity'] ?? null),
                'note' => $this->normalizeString($item['note'] ?? null),
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
