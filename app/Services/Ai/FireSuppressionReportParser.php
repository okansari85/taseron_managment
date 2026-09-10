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

        // U/UD/N MATRİS tablolarını (madde x ekipman) AI'a hiç göndermeden,
        // düz metin üzerinden DETERMİNİSTİK olarak (regex) ayrıştırıyoruz —
        // bu iş tamamen mekanik bir işaret-eşleştirme, AI'a bırakmak hem
        // ÇOK YAVAŞ (geniş bir tabloyu 100+ satırlık JSON'a dökmesi
        // dakikalarca sürüyordu) hem de HATALI (AI sütunları birbirine
        // karıştırıp aynı maddeyi mükerrer üretebiliyordu). Bir sayfada
        // matris bulunursa o sayfa için AI'dan control_items İSTENMEZ —
        // AI sadece marka/kat gibi bağlamsal alanları okumaya devam eder.
        $matricesByPage = array_map(
            fn (string $pageText) => $this->parseControlItemMatrices($pageText),
            $pages
        );

        // "6. TESPİT VE BULGULAR" bölümündeki "5.47) ..." gibi numaralı
        // bulgu maddeleri de matris gibi tamamen mekanik bir formattır —
        // AI'a hiç göndermeden regex ile ayrıştırılır (bkz. parseFindingsLines).
        $findingLinesByPage = array_map(
            fn (string $pageText) => $this->parseFindingsLines($pageText),
            $pages
        );

        // SIRALI — Http::pool ile paralel gönderim denendi ama NVIDIA NIM
        // ücretsiz katmanı eşzamanlı isteklerde bozuk/eksik JSON döndürüyor
        // (concurrency limiti gibi görünüyor); tek tek sıralı çağrı daha
        // yavaş ama güvenilir olan seçenek.
        $guesses = [];
        foreach ($pages as $index => $pageText) {
            $skipControlItems = ($matricesByPage[$index] ?? []) !== [];
            $skipFindings = ($findingLinesByPage[$index] ?? []) !== [];

            // Sayfa TAMAMEN bir U/UD/N matrisiyse (control_items zaten
            // deterministik bulundu) AI'ı bu sayfa için HİÇ ÇAĞIRMIYORUZ —
            // asıl kritik veri (ekipman kodu/durum/açıklama) zaten matris +
            // bulgu lookup'ından geliyor; marka/kat gibi ikincil bağlamsal
            // alanlar bu sayfalarda boş kalabilir (kabul edilebilir bir
            // ödün — hız için). Tarih/firma gibi başlık bilgileri raporun
            // HER sayfasında tekrar ettiği için başka bir sayfadan
            // (genelde 1.) zaten yakalanır, kaybolmaz.
            if ($skipControlItems) {
                $guesses[] = [];

                continue;
            }

            $prompt = $this->buildPrompt($categories, $skipControlItems, $skipFindings);

            try {
                $guesses[] = $this->ai->extractStructuredJson($prompt, $pageText);
            } catch (\Throwable $exception) {
                // Hangi sayfanın başarısız olduğu bilinmeden teşhis
                // imkansız — bu bilgi olmadan önceki hata mesajı sadece
                // "JSON ayrıştırılamadı" diyordu, 5 sayfadan hangisi
                // belli değildi.
                throw new \RuntimeException(
                    'Sayfa ' . ($index + 1) . '/' . count($pages) . ' işlenirken hata: ' . $exception->getMessage(),
                    previous: $exception
                );
            }
        }

        $guess = $this->mergeGuesses($guesses);

        $equipment = $this->normalizeEquipment(is_array($guess['equipment'] ?? null) ? $guess['equipment'] : [], $categories);
        $equipment = $this->applyDeterministicControlItems($equipment, $matricesByPage);

        // MİMARİ NOKTA: matris zaten equipment × control_item × sonuç
        // ilişkisini veriyor ("YD2 → 5.47 → uygun_degil"). Bulgu metni
        // ("5.47) ...") bu ilişkiyi TEKRAR KURMAZ, sadece 5.47'nin NEDEN
        // uygunsuz olduğunu açıklar — aynı açıklama o maddeyi paylaşan
        // TÜM ekipmanlar için geçerlidir. Bu yüzden AI'a/metne "bu bulgu
        // hangi ekipmana ait" diye SORULMUYOR — sadece madde koduyla basit
        // bir lookup yapılıyor (applyFindingDescriptions).
        $allFindingLines = array_merge(...$findingLinesByPage);
        $aiFindings = $this->normalizeFindings(is_array($guess['findings'] ?? null) ? $guess['findings'] : [], $categories);
        [$equipment, $findings] = $this->applyFindingDescriptions($equipment, $allFindingLines, $aiFindings);

        return [
            'control_date' => $this->normalizeDate($guess['control_date'] ?? null),
            'next_control_date' => $this->normalizeDate($guess['next_control_date'] ?? null),
            'overall_result' => $this->normalizeResult($guess['overall_result'] ?? null),
            'company_name' => $this->normalizeString($guess['company_name'] ?? null),
            'covered_categories' => $this->normalizeCategories(is_array($guess['covered_categories'] ?? null) ? $guess['covered_categories'] : [], $categories),
            'equipment' => $equipment,
            'findings' => $findings,
        ];
    }

    // ------------------------------------------------------------------
    // DETERMİNİSTİK MATRİS AYRIŞTIRMA — AI'ya hiç gitmeden düz metinden.
    // ------------------------------------------------------------------

    // Bir sayfada "No / Kod  YD1  YD2  ..." başlık satırını ve onu izleyen
    // "5.47 Hortum tamburu  UD  UD  ..." gibi madde satırlarını bulur, her
    // ekipman sütunu için code/title/status listesini üretir. Matris
    // bulunamazsa boş dizi döner (o zaman AI'dan control_items istenir —
    // güvenli geri dönüş).
    //
    // Dönüş: ['YD1' => [['code'=>'5.38','title'=>'...','status'=>'uygun'], ...], 'YD2' => [...], ...]
    private function parseControlItemMatrices(string $pageText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $pageText) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));

        $result = [];
        $codes = [];

        foreach ($lines as $line) {
            // Başlık satırı: "No / Kod YD1 YD2 YD3 ..." — yeni bir matris
            // başladığında sütun kodları güncellenir (bir sayfada birden
            // fazla matris olabilir, örn. YD1-10 ve YD11-20 ayrı tablo).
            if (preg_match('/No\s*\/?\s*Kod\b\s*(.+)$/iu', $line, $m)) {
                $tokens = $this->splitWhitespace($m[1]);

                if (count($tokens) >= 2) {
                    $codes = $tokens;

                    foreach ($codes as $code) {
                        $result[$code] ??= [];
                    }
                }

                continue;
            }

            if ($codes === []) {
                continue;
            }

            // Madde satırı: "5.47 Hortum tamburu UD UD UD UD ..."
            if (! preg_match('/^(\d+\.\d+)\s+(.+)$/u', $line, $m)) {
                continue;
            }

            $itemCode = $m[1];
            $tokens = $this->splitWhitespace($m[2]);
            $columnCount = count($codes);

            if (count($tokens) < $columnCount) {
                continue;
            }

            $statuses = array_slice($tokens, -$columnCount);

            if (! $this->looksLikeStatusRow($statuses)) {
                continue;
            }

            $title = trim(implode(' ', array_slice($tokens, 0, count($tokens) - $columnCount)));

            if ($title === '') {
                continue;
            }

            foreach ($codes as $columnIndex => $eqCode) {
                $status = $this->normalizeControlItemStatus($statuses[$columnIndex] ?? null);

                if ($status === null) {
                    // "N" (uygulanamaz/değerlendirme dışı) veya tanınmayan
                    // bir işaret — bu maddeyi bu ekipman için hiç ekleme.
                    continue;
                }

                $result[$eqCode][] = ['code' => $itemCode, 'title' => $title, 'status' => $status, 'description' => null];
            }
        }

        return array_filter($result, fn (array $items) => $items !== []);
    }

    // "6. TESPİT VE BULGULAR" bölümündeki "5.47) ..." gibi numaralı bulgu
    // maddelerini AI'a hiç göndermeden, düz metinden REGEX ile ayrıştırır
    // — bu format da (tıpkı U/UD/N matrisi gibi) tamamen mekanik: her
    // madde "N.NN)" ile başlar, sonraki numarasız satırlar (satır
    // sarması) önceki maddenin devamı sayılır. Böyle bir madde
    // bulunamazsa boş dizi döner (o zaman AI'dan findings istenir —
    // güvenli geri dönüş, örn. farklı bir rapor şablonu için).
    //
    // Dönüş: [['control_item' => '5.47', 'description' => '...'], ...]
    private function parseFindingsLines(string $pageText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $pageText) ?: [];
        $findings = [];
        $current = null;

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d+\.\d+(?:\s*-\s*\d+\.\d+)?)\)\s*(.+)$/u', $line, $m)) {
                if ($current !== null) {
                    $findings[] = $current;
                }

                $current = ['control_item' => $m[1], 'description' => trim($m[2])];

                continue;
            }

            // Bulgu bloğu başladıktan (en az 1 madde bulunduktan) SONRA
            // gelen "N. BAŞLIK" şeklinde bir üst-bölüm satırı ("7. NOTLAR"
            // gibi) bulgu bölümünün bittiğini gösterir. Bölümün KENDİ
            // başlığı ("6. TESPİT VE BULGULAR") henüz hiç madde
            // bulunmadan geldiği için yanlışlıkla "bitiş" sayılmaz.
            if (($findings !== [] || $current !== null) && preg_match('/^\d+\.\s+[A-ZÇĞİÖŞÜ]/u', $line)) {
                break;
            }

            if ($current !== null) {
                $current['description'] .= ' ' . $line;
            }
        }

        if ($current !== null) {
            $findings[] = $current;
        }

        return $findings;
    }

    // Bir bulgunun hangi ekipmana ait olduğunu AI'a ya da metne SORMUYORUZ
    // — madde kodu (örn. "5.47") zaten matriste hangi ekipmanların bunu
    // "uygun_degil" taşıdığını söylüyor, o yüzden burada sadece basit bir
    // kod → açıklama lookup'ı yapılıyor: her equipment.control_items[]
    // kaydının "description"ı, aynı koda sahip bulgunun metniyle
    // dolduruluyor (birden fazla ekipman aynı maddeyi paylaşıyorsa hepsi
    // AYNI açıklamayı alır — bu doğrudur, çünkü bulgu zaten o maddeyi
    // genel olarak açıklıyor, ekipmana özel değil).
    //
    // Matriste karşılığı olmayan bulgular (örn. "5.1-5.39" aralığı, pompa
    // checklist'i gibi matrissiz maddeler) equipment'e bağlanamaz — genel
    // bulgu listesi olarak (ikinci dönüş değeri) korunur.
    private function applyFindingDescriptions(array $equipment, array $findingLines, array $aiFindings): array
    {
        $matrixHasCode = [];
        foreach ($equipment as $eq) {
            foreach ($eq['control_items'] ?? [] as $ci) {
                if ($ci['code'] !== null) {
                    $matrixHasCode[$this->normalizeCodeKey($ci['code'])] = true;
                }
            }
        }

        $descriptionByCode = [];
        $generalFindings = [];

        $addLine = function (?string $rawCode, string $description) use (&$descriptionByCode, &$generalFindings, $matrixHasCode): void {
            $itemCode = $this->extractMaddeCode($rawCode);
            $key = $itemCode !== null ? $this->normalizeCodeKey($itemCode) : null;

            if ($key !== null && isset($matrixHasCode[$key])) {
                $descriptionByCode[$key] = isset($descriptionByCode[$key])
                    ? $descriptionByCode[$key] . ' ' . $description
                    : $description;

                return;
            }

            $generalFindings[] = [
                'category' => null,
                'control_item' => $rawCode,
                'description' => $description,
                'scope' => 'unknown',
                'area_note' => null,
                'is_uncertain' => false,
            ];
        };

        foreach ($findingLines as $line) {
            $addLine($line['control_item'], $line['description']);
        }

        foreach ($aiFindings as $finding) {
            $addLine($finding['control_item'], $finding['description']);
        }

        foreach ($equipment as $i => $eq) {
            $udDescriptions = [];

            foreach ($eq['control_items'] ?? [] as $j => $ci) {
                if ($ci['code'] === null) {
                    continue;
                }

                $key = $this->normalizeCodeKey($ci['code']);

                if (! isset($descriptionByCode[$key])) {
                    continue;
                }

                $equipment[$i]['control_items'][$j]['description'] = $descriptionByCode[$key];

                if ($ci['status'] === 'uygun_degil') {
                    $udDescriptions[] = $descriptionByCode[$key];
                }
            }

            if ($udDescriptions !== []) {
                // Bulgudan gelen GERÇEK açıklama, madde başlıklarından
                // daha bilgilendiricidir — equipment.note'u bununla
                // değiştiriyoruz.
                $equipment[$i]['note'] = implode(' ', array_values(array_unique($udDescriptions)));
            }
        }

        return [$equipment, $generalFindings];
    }

    private function splitWhitespace(string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($value)) ?: [], fn (string $t) => $t !== ''));
    }

    private function looksLikeStatusRow(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (! preg_match('/^(U|UD|N|-)$/iu', $token)) {
                return false;
            }
        }

        return true;
    }

    // Deterministik olarak bulunan matris sonuçlarını normalizeEquipment()
    // çıktısına koda göre işler — kod zaten AI'ın equipment listesinde
    // varsa üzerine yazar (matris = tek doğruluk kaynağı), yoksa (AI o
    // sütunu hiç bulamadıysa) en azından kod + maddeleriyle yeni bir kayıt
    // ekler. Sonrasında result/note bu GERÇEK verilerden yeniden hesaplanır.
    private function applyDeterministicControlItems(array $equipment, array $matricesByPage): array
    {
        $mergedItems = [];
        $displayCode = [];

        foreach ($matricesByPage as $pageMatrix) {
            foreach ($pageMatrix as $code => $items) {
                $key = $this->normalizeCodeKey($code);
                $mergedItems[$key] = $this->mergeControlItemLists($mergedItems[$key] ?? [], $items);
                $displayCode[$key] ??= $code;
            }
        }

        if ($mergedItems === []) {
            return $equipment;
        }

        $indexByKey = [];
        foreach ($equipment as $i => $item) {
            if ($item['code'] !== null) {
                $indexByKey[$this->normalizeCodeKey($item['code'])] = $i;
            }
        }

        foreach ($mergedItems as $key => $items) {
            if (isset($indexByKey[$key])) {
                $equipment[$indexByKey[$key]]['control_items'] = $items;

                continue;
            }

            // AI bu ekipmanı hiç bulamadı ama matriste kodu var — en
            // azından kod + maddeleriyle bir kayıt oluştur, kaybolmasın.
            $equipment[] = [
                'code' => $displayCode[$key],
                'category' => null,
                'location_note' => null,
                'brand' => null,
                'model' => null,
                'serial_no' => null,
                'result' => null,
                'note' => null,
                'control_items' => $items,
                'is_uncertain' => false,
            ];
        }

        foreach ($equipment as $i => $item) {
            if (empty($item['control_items'])) {
                continue;
            }

            $hasNonconformity = false;
            foreach ($item['control_items'] as $ci) {
                if ($ci['status'] === 'uygun_degil') {
                    $hasNonconformity = true;

                    break;
                }
            }

            $equipment[$i]['result'] = $hasNonconformity ? 'uygun_degil' : 'uygun';

            if (empty($equipment[$i]['note']) && $hasNonconformity) {
                $udTitles = array_values(array_filter(array_map(
                    fn (array $ci) => $ci['status'] === 'uygun_degil' ? $ci['title'] : null,
                    $item['control_items']
                )));

                if ($udTitles !== []) {
                    $equipment[$i]['note'] = implode('; ', $udTitles);
                }
            }
        }

        return array_values($equipment);
    }

    // Ekipman kodlarını (AI'dan gelen ile matristen gelen) karşılaştırırken
    // hem harf büyüklüğü hem aradaki olası boşluk farklarını yok sayar
    // (örn. "YD1" ile "yd 1" aynı kabul edilsin).
    private function normalizeCodeKey(string $code): string
    {
        return $this->toLowerTr(preg_replace('/\s+/u', '', trim($code)) ?? '');
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
                    // NOT: matris bulunan sayfalarda AI'dan zaten
                    // control_items istenmiyor, bu dal artık sadece AI'ın
                    // (matris bulunamayan sayfalarda) ürettiği düzyazı
                    // madde-sonuç listeleri için çalışıyor.
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

    // Aynı ekipmanın control_items'ini birleştirirken madde kodu (yoksa
    // başlığı) anahtar olarak kullanılır — böylece aynı madde iki kez
    // eklenemez (mükerrerlik burada YAPISAL olarak engellenir), sonraki
    // kaynaktan gelen aynı maddeli kayıt öncekinin üzerine yazar.
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

    // $skipControlItems: bu sayfada matris deterministik olarak zaten
    // ayrıştırıldıysa true — o zaman AI'dan control_items İSTENMEZ (hem
    // gereksiz hem YAVAŞ hem HATAYA AÇIK), AI sadece marka/kat gibi
    // bağlamsal alanları okumaya devam eder.
    // $skipFindings: bu sayfada "N.NN) ..." formatlı bulgu maddeleri
    // deterministik olarak zaten ayrıştırıldıysa true — AI'dan "findings"
    // HİÇ İSTENMEZ (bulgu analizinde artık AI kullanılmıyor, sadece
    // matris/regex + basit kod-eşleştirmesi).
    private function buildPrompt(array $categories, bool $skipControlItems = false, bool $skipFindings = false): string
    {
        $categoryList = implode(', ', $categories);

        $controlItemsSchemaField = $skipControlItems
            ? ''
            : ', "control_items": [{"code": "madde numarası, örn. 5.47", "title": "madde metni, örn. Hortum tamburu", "status": "uygun"|"uygun_degil"}]';

        $controlItemsSection = $skipControlItems
            ? "\nBu sayfadaki U/UD/N madde-matrisi (varsa) AYRI, deterministik bir mekanizmayla zaten işlendi — equipment kayıtlarına \"control_items\" alanını EKLEME/DOLDURMA. has_nonconformity alanını yine de matristeki UD işaretlerine bakarak var/yok şeklinde doldurabilirsin (zararı yok, kullanılmayabilir de)."
            : <<<CI

"equipment[].control_items" — ÇOK ÖNEMLİ, rapordaki GERÇEK kontrol maddelerini (sabit/önceden tanımlı bir liste DEĞİL, o SAYFADA yazan maddeler) ekipman bazında çıkarmak için: kontrol maddeleri genelde numaralı bir MATRİS tablosunda olur — SATIRLAR numaralı kontrol maddeleridir (örn. "5.38 Hortumda TSE standardı varlığı", "5.47 Hortum tamburu"), SÜTUNLAR ekipman kodlarıdır (örn. YD1, YD2, ... YD10), HÜCRELER o ekipmanın o maddedeki sonucudur (U=uygun, UD=uygun değil, N=uygulanamaz/yok). Böyle bir matris gördüğünde HER ekipman sütunu için HER satırı (N olanlar HARİÇ) o ekipmanın "control_items" dizisine bir obje olarak ekle: code=satır no, title=satır başlığı (aynen metindeki gibi), status="uygun" (hücre U ise) veya "uygun_degil" (hücre UD ise). Hücre "N" ise o maddeyi HİÇ EKLEME. AYNI MADDEYİ AYNI EKİPMAN İÇİN ASLA İKİ KEZ EKLEME. Örnek:
  Girdi (matris, kısmi):
    No/Kod                                      YD1  YD2
    5.38 Hortumda TSE standardı varlığı           U    U
    5.39 Projede gösterilen yerde ve özellikte olması  UD   UD
    5.47 Hortum tamburu                            UD   U
    5.52 Hortum Kılavuzu                            N    N
  Çıktı — YD1 kaydının control_items dizisi: [{"code":"5.38","title":"Hortumda TSE standardı varlığı","status":"uygun"},{"code":"5.39","title":"Projede gösterilen yerde ve özellikte olması","status":"uygun_degil"},{"code":"5.47","title":"Hortum tamburu","status":"uygun_degil"}] (5.52 satırı "N" olduğu için YOK). YD2 kaydının control_items dizisinde ise 5.47 "status":"uygun" olur (o hücre U). Matris değil de tek bir ekipman için düzyazı/liste halinde madde-sonuç eşleşmesi varsa (matris olmadan), yine aynı şekilde her maddeyi code/title/status ile control_items dizisine ekle. Bu sayfada hiçbir kontrol maddesi/matris YOKSA control_items dizisini boş [] bırak — UYDURMA, önceden tanımlı bir liste kullanma, SADECE bu sayfada gerçekten yazan maddeleri çıkar.
CI;

        $findingsSchemaField = $skipFindings
            ? ''
            : ',
  "findings": [{"category": "kategori_kodu", "control_item": "madde numarası varsa, örn. \'5.47\'", "description": "string", "scope": "specific"|"area"|"unknown", "area_note": "string"}]';

        $findingsSection = $skipFindings
            ? "\nBu sayfadaki \"N.NN) ...\" formatlı numaralı bulgu maddeleri (varsa) AYRI, deterministik bir mekanizmayla zaten işlendi — \"findings\" alanını HİÇ ÜRETME/DOLDURMA."
            : "\n\"findings\" dizisi SADECE düzyazı (cümle) halinde yazılmış tespit/bulgu/öneri maddeleridir (genellikle \"TESPİT VE BULGULAR\" başlıklı bir bölümde bulunur). Bu sayfada böyle bir bölüm/cümle YOKSA findings dizisini BOŞ [] bırak. Bir ekipman tablosundaki U/UD/N işaretlerini veya sayısal ölçüm değerlerini ASLA finding description'ı olarak yazma — bunlar finding değildir.\n\"findings[].description\": Cümleyi AYNEN metindeki gibi kopyala (içinde geçen ekipman kodları dahil, kısaltma/özetleme yapma) — bu cümledeki ekipman kodlarını hangi ekipmana bağlayacağımızı AYRI, deterministik bir adımda BİZ metinden çıkaracağız, sen bunun için ayrı bir liste üretme.";

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
  "equipment": [{"code": "string", "category": "kategori_kodu", "location_note": "string", "brand": "marka", "model": "model", "serial_no": "seri no", "has_nonconformity": true|false, "note": "bu ekipmana özel tespit/bulgu açıklaması (varsa)"{$controlItemsSchemaField}}]{$findingsSchemaField}
}

ÖNEMLİ — Ekipmanlar tablo halinde, SÜTUN olarak listelenmiş olabilir (örn. "YD1 YD2 YD3 ... YD20" başlıkları bir satırda, altında marka/uzunluk/kontrol maddesi sonuçları (U/UD/N) her sütun için ayrı ayrı verilir). Bu durumda TABLODAKİ HER SÜTUNU (her ekipman kodunu) AYRI bir "equipment" kaydı olarak çıkar — 5, 10, 20, hatta daha fazla ekipman olabilir, HİÇBİRİNİ ATLAMA.
AYNI KURAL POMPALAR İÇİN DE GEÇERLİ — "Pompa No 1 2 3 Jokey" gibi bir başlık satırından sonra Marka/Seri No/Yakıt/Güç/Debi/Basınç gibi satırlar geliyorsa, HER POMPA SÜTUNU (boş/"-" olanlar hariç) AYRI bir "equipment" kaydıdır, category="yangin_pompasi", code alanına "Pompa 1", "Pompa 2" gibi sütun başlığından türettiğin bir isim yaz (rapor açık bir kod vermiyorsa bile), brand=Marka satırındaki değer, serial_no=Seri No satırındaki değer. Örnek:
  Girdi:
    Pompa No       1        2        3    Jokey
    Marka          MAS      MAS      -    -
    Seri No        A1205075 A1205075 -    -
  Çıktı equipment kayıtları: {"code":"Pompa 1","category":"yangin_pompasi","brand":"MAS","serial_no":"A1205075",...} ve {"code":"Pompa 2","category":"yangin_pompasi","brand":"MAS","serial_no":"A1205075",...} — "3" ve "Jokey" sütunları ATLANIR çünkü o sütundaki TÜM satırlar (Marka, Seri No, Yakıt, Güç, Debi, Basınç) "-" değerinde; bu, o cihazın kurulu OLMADIĞI anlamına gelir, "değerlendirme dışı jokey pompa" gibi bir yorum UYDURMA. KURAL: bir sütunda Marka/Seri No dahil TÜM satır değerleri "-" veya boşsa, o sütun için HİÇ equipment kaydı oluşturma.
"equipment[].has_nonconformity": SAYIM YAPMA, SADECE VAR/YOK kontrolü yap — o ekipmanın sütunundaki kontrol maddesi satırlarını (U/UD/N) tek tek tara, İÇLERİNDE EN AZ BİR TANE "UD" (Uygun Değil) işareti VARSA true yaz; hiç "UD" yoksa (hepsi "U" veya "N" ise) false yaz. Kaç tane UD olduğunu SAYMANA gerek yok, sadece "en az bir tane var mı" sorusuna cevap ver — bu çok daha kolay ve hataya kapalıdır.
"equipment[].note": Raporun "TESPİT VE BULGULAR"/"6. TESPİT VE BULGULAR" gibi bir bölümünde bu ekipmanın kodu açıkça geçiyorsa (örn. "YD14 ve YD15 nolu yangın dolaplarında hasar var düzeltilmelidir"), o cümleyi/açıklamayı bu ekipmanın "note" alanına yaz.{$controlItemsSection}{$findingsSection}
overall_result için: satır içindeki "U"/"UD" kısaltmalarıyla KARIŞTIRMA — sadece raporun nihai SONUÇ/KANAAT cümlesini kullan.
Tarihi veya sonuç ifadelerini normalize etmeye ÇALIŞMA — metinde ne yazıyorsa onu aynen döndür, bu işi başka bir katman yapacak.

ÖNEMLİ — Sana verilen metin BÜYÜK bir raporun SADECE BİR SAYFASI olabilir (rapor sayfa sayfa işleniyor). Bu sayfada bir bilgi (örn. control_date, company_name, overall_result) YOKSA bu NORMALDİR — o alanı null bırak. ASLA "metinde bulunamadı", "belirtilmemiş" gibi bir AÇIKLAMA CÜMLESİ yazma — sadece JSON null kullan, string değer olarak "yok"/"bulunamadı" gibi bir metin ASLA yazma.
"equipment" kaydı SADECE gerçek bir ekipman tablosundan (kod/marka/seri no vb. sütunları olan bir tablo) türetilir. Bir "TESPİT VE BULGULAR" cümlesinde geçen bir ekipmandan (örn. "Dizel pompa çalışmıyor") bahsediliyor diye o cümleden YENİ bir equipment kaydı UYDURMA — bu sayfada o ekipmanın tablosu yoksa hiçbir equipment kaydı oluşturma, sadece bir finding olarak yaz.
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

    // Aynı madde koduyla birden fazla obje gelirse (AI'ın ender de olsa
    // aynı maddeyi tekrar yazması ihtimaline karşı) mergeControlItemLists
    // ile aynı dedup mantığı burada da uygulanır — SON kez tekrar etmiş
    // olsa bile sonuçta her kod en fazla BİR KEZ yer alır.
    private function normalizeControlItems(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = array_values(array_filter(array_map(function ($ci) {
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
                // Bulgu metninden (applyFindingDescriptions) sonradan
                // doldurulur — burada henüz bilinmiyor.
                'description' => null,
            ];
        }, $raw)));

        return $this->mergeControlItemLists([], $items);
    }

    // AI'dan artık sadece matriste karşılığı OLMAYAN (aralık/pompa vb.)
    // sayfalarda, bulgu regex'i hiçbir şey bulamadığında fallback olarak
    // istenir — bkz. parse()'daki $skipFindings. equipment_codes YOK
    // artık: bir bulgunun hangi ekipmana ait olduğu AI'a/metne değil,
    // sadece control_item koduyla matrise bakılarak (applyFindingDescriptions)
    // belirlenir.
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
                'is_uncertain' => $description === '',
            ];
        }, $rawFindings));
    }

    // "5.47", "5.47)", "Madde 5.47" gibi varyasyonlardan sadece "5.47"
    // kısmını çıkarır.
    private function extractMaddeCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/\d+\.\d+/', $value, $m) === 1 ? $m[0] : null;
    }
}
