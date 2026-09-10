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

        // SIRALI — Http::pool ile paralel gönderim denendi ama NVIDIA NIM
        // ücretsiz katmanı eşzamanlı isteklerde bozuk/eksik JSON döndürüyor
        // (concurrency limiti gibi görünüyor); tek tek sıralı çağrı daha
        // yavaş ama güvenilir olan seçenek.
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

            // control_date/next_control_date/company_name genelde ilk
            // sayfadaki başlık bilgisidir — İLK bulunan değer korunur (sonraki
            // sayfalarda yanlışlıkla üretilen bir metin onu ezmesin).
            foreach (['control_date', 'next_control_date', 'company_name'] as $field) {
                if (empty($merged[$field]) && ! empty($guess[$field]) && is_string($guess[$field]) && ! $this->looksLikeMissingValue($guess[$field])) {
                    $merged[$field] = $guess[$field];
                }
            }

            // overall_result ("SONUÇ VE KANAAT") genelde raporun EN SONUNDAKİ
            // sayfadadır — SON bulunan değer kazanır.
            if (! empty($guess['overall_result']) && is_string($guess['overall_result']) && ! $this->looksLikeMissingValue($guess['overall_result'])) {
                $merged['overall_result'] = $guess['overall_result'];
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

                    // control_items dizi olduğu için "boşsa doldur" mantığı
                    // yetmez — aynı ekipman kodu farklı sayfalarda farklı
                    // maddelerle geçebilir (örn. matris birden fazla sayfaya
                    // bölünmüşse), bu yüzden madde koduna göre birleştirilir.
                    if ($key === 'control_items') {
                        $equipmentByCode[$code]['control_items'] = $this->mergeControlItemLists(
                            is_array($equipmentByCode[$code]['control_items'] ?? null) ? $equipmentByCode[$code]['control_items'] : [],
                            is_array($value) ? $value : []
                        );

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

    // Aynı ekipmanın control_items'ini iki sayfadan birleştirirken madde
    // kodu (yoksa başlığı) anahtar olarak kullanılır — sonraki sayfadan
    // gelen aynı maddeli kayıt, öncekinin üzerine yazar (daha tam/güncel
    // kabul edilir), yeni maddeler diziye eklenir.
    private function mergeControlItemLists(array $existing, array $incoming): array
    {
        $byKey = [];

        foreach ([...$existing, ...$incoming] as $ci) {
            if (! is_array($ci)) {
                continue;
            }

            $key = $this->toLowerTr(trim((string) ($ci['code'] ?? $ci['title'] ?? '')));

            if ($key === '') {
                continue;
            }

            $byKey[$key] = $ci;
        }

        return array_values($byKey);
    }

    // Prompt'ta "bulunamadı/belirtilmemiş gibi bir cümle yazma, null kullan"
    // dememize rağmen model bazen yine de bir "eksik veri" açıklaması
    // döndürebiliyor — bu, gerçek bir veri değeri gibi başka bir sayfadaki
    // doğru değerin üzerine yazılmasın diye ek bir güvenlik katmanı.
    private function looksLikeMissingValue(string $value): bool
    {
        $lower = $this->toLowerTr(trim($value));

        foreach (['bulunamadı', 'bulunmamaktadır', 'bulunmuyor', 'belirtilmemiş', 'belirtilmiyor', 'mevcut değil', 'yer almamaktadır', 'geçmemektedir', 'metinde yok', 'metinde geçmiyor'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
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
  "equipment": [{"code": "string", "category": "kategori_kodu", "location_note": "string", "brand": "marka", "model": "model", "serial_no": "seri no", "has_nonconformity": true|false, "note": "bu ekipmana özel tespit/bulgu açıklaması (varsa)", "control_items": [{"code": "madde numarası, örn. 5.47", "title": "madde metni, örn. Hortum tamburu", "status": "uygun"|"uygun_degil"}]}],
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
"equipment[].control_items" — ÇOK ÖNEMLİ, rapordaki GERÇEK kontrol maddelerini (sabit/önceden tanımlı bir liste DEĞİL, o SAYFADA yazan maddeler) ekipman bazında çıkarmak için: kontrol maddeleri genelde numaralı bir MATRİS tablosunda olur — SATIRLAR numaralı kontrol maddeleridir (örn. "5.38 Hortumda TSE standardı varlığı", "5.47 Hortum tamburu"), SÜTUNLAR ekipman kodlarıdır (örn. YD1, YD2, ... YD10), HÜCRELER o ekipmanın o maddedeki sonucudur (U=uygun, UD=uygun değil, N=uygulanamaz/yok). Böyle bir matris gördüğünde HER ekipman sütunu için HER satırı (N olanlar HARİÇ) o ekipmanın "control_items" dizisine bir obje olarak ekle: code=satır no, title=satır başlığı (aynen metindeki gibi), status="uygun" (hücre U ise) veya "uygun_degil" (hücre UD ise). Hücre "N" ise o maddeyi HİÇ EKLEME. Örnek:
  Girdi (matris, kısmi):
    No/Kod                                      YD1  YD2
    5.38 Hortumda TSE standardı varlığı           U    U
    5.39 Projede gösterilen yerde ve özellikte olması  UD   UD
    5.47 Hortum tamburu                            UD   U
    5.52 Hortum Kılavuzu                            N    N
  Çıktı — YD1 kaydının control_items dizisi: [{"code":"5.38","title":"Hortumda TSE standardı varlığı","status":"uygun"},{"code":"5.39","title":"Projede gösterilen yerde ve özellikte olması","status":"uygun_degil"},{"code":"5.47","title":"Hortum tamburu","status":"uygun_degil"}] (5.52 satırı "N" olduğu için YOK). YD2 kaydının control_items dizisinde ise 5.47 "status":"uygun" olur (o hücre U). Matris değil de tek bir ekipman için düzyazı/liste halinde madde-sonuç eşleşmesi varsa (matris olmadan), yine aynı şekilde her maddeyi code/title/status ile control_items dizisine ekle. Bu sayfada hiçbir kontrol maddesi/matris YOKSA control_items dizisini boş [] bırak — UYDURMA, önceden tanımlı bir liste kullanma, SADECE bu sayfada gerçekten yazan maddeleri çıkar.
"findings[].equipment_codes": TESPİT VE BULGULAR bölümündeki her madde için, o maddede adı geçen TÜM ekipman kodlarını listele — bazen tek tek ("YD19"), bazen virgülle ayrılmış uzun bir liste halinde geçebilir ("YD1, YD2, YD3, YD4, YD14, ..."), hiçbirini atlama. Madde birden fazla ekipmandan bahsediyorsa hepsini equipment_codes dizisine ekle. Bu dizide SADECE equipment listesindeki gerçek "code" değerleri olur — asla kategori adı ("yangin_pompasi" gibi) yazma; ilgili ekipmanın kodu belli değilse equipment_codes'u boş bırak.
overall_result için: satır içindeki "U"/"UD" kısaltmalarıyla KARIŞTIRMA — sadece raporun nihai SONUÇ/KANAAT cümlesini kullan.
Tarihi veya sonuç ifadelerini normalize etmeye ÇALIŞMA — metinde ne yazıyorsa onu aynen döndür, bu işi başka bir katman yapacak.

ÖNEMLİ — Sana verilen metin BÜYÜK bir raporun SADECE BİR SAYFASI olabilir (rapor sayfa sayfa işleniyor). Bu sayfada bir bilgi (örn. control_date, company_name, overall_result) YOKSA bu NORMALDİR — o alanı null bırak. ASLA "metinde bulunamadı", "belirtilmemiş" gibi bir AÇIKLAMA CÜMLESİ yazma — sadece JSON null kullan, string değer olarak "yok"/"bulunamadı" gibi bir metin ASLA yazma.
"findings" dizisi SADECE düzyazı (cümle) halinde yazılmış tespit/bulgu/öneri maddeleridir (genellikle "TESPİT VE BULGULAR" başlıklı bir bölümde bulunur). Bu sayfada böyle bir bölüm/cümle YOKSA findings dizisini BOŞ [] bırak. Bir ekipman tablosundaki U/UD/N işaretlerini veya sayısal ölçüm değerlerini ASLA finding description'ı olarak yazma — bunlar finding değildir.
"equipment" kaydı SADECE gerçek bir ekipman tablosundan (kod/marka/seri no vb. sütunları olan bir tablo) türetilir. Bir "TESPİT VE BULGULAR" cümlesinde geçen bir ekipmandan (örn. "Dizel pompa çalışmıyor") bahsediliyor diye o cümleden YENİ bir equipment kaydı UYDURMA — sadece mevcut equipment kodlarına (bu sayfada tablo varsa) veya findings[].equipment_codes'a referans ver; bu sayfada o ekipmanın tablosu yoksa hiçbir equipment kaydı oluşturma, sadece bir finding olarak yaz.
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
            $controlItems = $this->normalizeControlItems($item['control_items'] ?? []);

            // control_items varsa (rapordan gerçekten madde çıkarılabildiyse)
            // uygun/uygun_degil kararı BUNDAN türetilir — has_nonconformity
            // alanına ek/çapraz kontrol olarak değil, tek doğruluk kaynağı
            // olarak kullanılır. control_items boşsa (örn. düzyazı rapor,
            // madde tablosu yok) has_nonconformity'e geri dönülür.
            $hasNonconformityFromItems = false;
            foreach ($controlItems as $ci) {
                if ($ci['status'] === 'uygun_degil') {
                    $hasNonconformityFromItems = true;

                    break;
                }
            }

            $note = $this->normalizeString($item['note'] ?? null);
            if ($note === null && $controlItems !== []) {
                $udTitles = array_values(array_filter(array_map(
                    fn (array $ci) => $ci['status'] === 'uygun_degil' ? $ci['title'] : null,
                    $controlItems
                )));

                if ($udTitles !== []) {
                    $note = implode('; ', $udTitles);
                }
            }

            return [
                'code' => $code,
                'category' => $this->normalizeCategory($item['category'] ?? null, $categories),
                'location_note' => $locationNote,
                'brand' => $brand,
                'model' => $model,
                'serial_no' => $this->normalizeString($item['serial_no'] ?? null),
                'result' => $controlItems !== []
                    ? ($hasNonconformityFromItems ? 'uygun_degil' : 'uygun')
                    : $this->resultFromHasNonconformity($item['has_nonconformity'] ?? null),
                'note' => $note,
                'control_items' => $controlItems,
                // Ne kesin (kod) ne aday (marka/model/konum) araması için
                // hiçbir dayanağı yoksa belirsiz olarak işaretlenir.
                'is_uncertain' => $code === null && $brand === null && $model === null && $locationNote === null,
            ];
        }, $rawEquipment));
    }

    // AI'a literal "uygun"/"uygun_degil" istesek de bazen "U"/"UD" kısaltmasını
    // veya boşluklu "uygun değil" yazabiliyor — hepsini tek bir yere topluyoruz.
    private function normalizeControlItemStatus(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = $this->toLowerTr(trim($value));

        if ($value === 'ud' || str_contains($value, 'degil') || str_contains($value, 'değil') || str_contains($value, 'uygunsuz')) {
            return 'uygun_degil';
        }

        if ($value === 'u' || str_contains($value, 'uygun')) {
            return 'uygun';
        }

        return null;
    }

    private function normalizeControlItems(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($ci) {
            $ci = is_array($ci) ? $ci : [];
            $title = $this->normalizeString($ci['title'] ?? null);
            $status = $this->normalizeControlItemStatus($ci['status'] ?? null);

            if ($title === null || $status === null) {
                return null;
            }

            return [
                'code' => $this->normalizeString($ci['code'] ?? null),
                'title' => $title,
                'status' => $status,
            ];
        }, $raw)));
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
