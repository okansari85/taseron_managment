<?php

namespace App\Services\Ai\PkTakip;

/**
 * Kısa talimat açıkken (yerel model) tesisat raporlarında bulguların ikinci okuması: küçük model uzun talimat + uzun
 * raporla kusur listesinin kodsuz / sondaki maddelerini atlıyor. Yalnız kusur maddelerini soran kısa bir istek atılır;
 * sistem eşleştirmesini de bu istekte model yapar (ana okumanın sistemlerinden seçer), seçemediği madde genel bulgu olur.
 */
class PkFindingsPass
{
    private const PROMPT = <<<'PROMPT'
Sana bir periyodik kontrol raporunun metni verilecek. Görevin YALNIZCA raporun kusur / uygunsuzluk / eksiklik maddelerini eksiksiz listelemek.
- Kusur bölümünün başlığı firmaya göre değişir: "KUSUR AÇIKLAMALARI / NOTLAR", "UYGUNSUZLUKLAR", "TESPİT EDİLEN EKSİKLİKLER", "AÇIKLAMALAR" gibi. Bu bölümdeki HER satır / madde ayrı bir öğedir: madde kodu olsun olmasın (örn. "6.D.9. ..." ve kodsuz "İDARİ BİNA YD-7 DOLABINDA ...") hepsini al; hiçbirini atlama, birleştirme ya da kısaltma. Bölüm uzun olabilir, sonuna kadar oku. Kısa satırlar da (örn. tek cümlelik "Pano kapağı yok.") ayrı maddedir; başka bir maddeye benziyor diye atlama.
- text = maddenin raporda yazdığı gibi tam metni (madde kodu dahil). Bir madde birden çok satıra bölünmüşse satırları birleştir.
- Sonuç işaretleri rapordan rapora değişir ("UD", "U.D", "U.D.", "Uygun D.", "G.D", "✘", "Uygun Değil" gibi). Hangi işaretin "uygun değil" anlamına geldiğini raporun lejantından / açıklamasından öğren.
- Kontrol listesi / kriter maddeleri (sonucu ne olursa olsun) kusur DEĞİLDİR: bunlar genelde soru biçimindedir ve sonunda bir sonuç işareti vardır (örn. "B.3. Pano kapakları kilitli midir? U", "C.2. Aydınlatma normal midir? G"). Kusur, raporun kusur / uygunsuzluk bölümünde yazan açıklamadır.
- Raporun kapsamı, yöntemi, sınır değer açıklamaları; sonuç ve kanaat cümlesi kusur DEĞİLDİR.
- Ölçüm tablolarının satırlarını (ölçüm noktası, ölçülen değer) kusur olarak yazma; onlar ayrıca okunur.
- Tesis / ekipman bilgileri ve soru-cevap satırları (örn. "Kullanım amacı: Depo", "Elektrik tesisatı projesi var mı? Var") kusur DEĞİLDİR. Kusur, bir eksikliği / uygunsuzluğu / yapılması gereken düzeltmeyi anlatan maddedir.
- from = maddenin rapordaki yeri: "kusur_bolumu" (kusur / uygunsuzluk / eksiklik / not bölümü), "kontrol_listesi" (kriter / soru maddesi), "diger" (başka yer).
- Kusur yoksa items = []. Kusur olmadığını söyleyen cümleler ("kusur belirtilmemiştir", "uygunsuzluk yoktur", "-") öğe DEĞİLDİR; bölüm boşsa items = [].
PROMPT;

    // Bölüm alt ajanı: girdi yalnız raporun kusur bölümüdür (PkDocumentSections); bütün raporun kurallarına gerek yok.
    private const SECTION_PROMPT = <<<'PROMPT'
Sana bir periyodik kontrol raporunun KUSUR / UYGUNSUZLUK bölümü verilecek. Bu bölümdeki HER madde / satır ayrı bir kusurdur: madde kodu olsun olmasın (örn. "6.D.9. ..." ya da kodsuz "İDARİ BİNA YD-7 DOLABINDA ..."), hiçbirini atlama, birleştirme ya da kısaltma.
- text = maddenin raporda yazdığı gibi tam metni (madde kodu dahil).
- Kusur olmadığını söyleyen cümleler ("kusur belirtilmemiştir", "uygunsuzluk yoktur", "-") öğe değildir; bölüm boşsa items = [].
PROMPT;

    // Havalandırma raporunda debi ölçüm tablosunun "UYGUN DEĞİL" satırları da kusurdur (uzman öyle kaydediyor).
    private const MEASUREMENT_ROWS = <<<'PROMPT'
- İSTİSNA: Bu raporda ölçüm tablosunda sonucu "UYGUN DEĞİL" olan her satır da bir kusurdur: text = "<nokta no / mahal> menfezinde debi <ölçülen debi> m³/h; UYGUN DEĞİL.", from = "olcum_tablosu". Sonucu UYGUN olan ölçüm satırları kusur değildir.
PROMPT;

    // $wholeText: kusur bölümü verildiğinde yedek metin (PkPageSections::merge: Docling'in kusur bölümü ya da kısa raporda bütün
    // metin); bölümde kusur bulunamazsa (yanlış bölüm) arama oradan. $measurementTable: sistem tablolarının satırları
    // ([['system' => ..., 'line' => "| … |"]], havalandırma); uygun olmayan ölçüm satırları da bulgu olur. $equipmentLinks: bulgudaki
    // cihaz numaraları da okunur (affected_equipment).
    public function apply(array $semantic, string $documentText, ?string $provider = null, bool $measurementRows = false, array $systemEquipment = [], bool $sectionOnly = false, ?string $wholeText = null, ?array $measurementTable = null, bool $equipmentLinks = false): array
    {
        $systems = collect((array) ($semantic['template']['fire_systems']['systems'] ?? []))->pluck('system_name')->filter()->unique()->values()->all();
        // Bütün rapor verildiğinde her madde nereden alındığını söyler; yalnız kusur bölümünden (havalandırmada ölçüm tablosundan)
        // gelenler bulgu olur: küçük model kriter maddelerini de yazabiliyor (NuExtract3, #38: kusur bölümü boş, 16 kriter).
        $sources = ['kusur_bolumu', ...($measurementRows ? ['olcum_tablosu'] : []), 'kontrol_listesi', 'diger'];
        $item = $sectionOnly
            ? ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]
            : ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'from' => ['type' => 'string', 'enum' => $sources]], 'required' => ['text', 'from']];
        $schema = ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => $item]], 'required' => ['items']];

        // Bütün rapor verildiyse önce kusur bölümü ajanı bölümü bulup aynen kopyalar; bulgular yalnız o metinden. Bölüm boşsa bulgu
        // yok (istek de yok): düz metinde bölüm sınırı görünmüyor, küçük model başlığın üstündeki kriteri kusur sanıyordu
        // (NuExtract3, 13 kapı raporu: "16. Acil stop … NU"). Ayrı kusur bölümü olmayan raporda eski yol.
        // Docling'in kusur bölümü verildiyse de aynı ajan bölümü doğrular: bölümün içindeki kusur / not satırlarını kopyalar.
        // Etiketleyici kusur bölümü diye lejant satırını seçip altındaki kontrol listesini veriyordu (PK25-0092-004: "NOTLAR" tablo
        // hücresi, başlık değil; 28 kriter bulgu oldu). Bölümde kusur yoksa (yanlış bölüm) aynı ajan bütün metinde arar; orada da
        // yoksa kaynak süzgeci (yalnız kusur / not kaynaklı maddeler).
        if (!$measurementRows) {
            // Bölüm verildiyse (harita / Docling) başlık aranmaz, bölümdeki kusur satırları kopyalanır: başlık kipinde ajan bölümün
            // içindeki ilk başlıktan sonrasını alıyordu (PK.239: "7. NOTLAR" mevzuat notu kopyalandı, 35 bulgu düştü).
            // Docling'in kusur bölümü yalnız başlıktan ibaretse bölüm boştur: bulgu yok, yedek metne gidilmez. Bütün metindeki başlık
            // kipi ajanı boş başlıktan sonraki sonuç cümlesini kusur diye kopyalıyordu (#126: "YAZILI EKSİKLER GİDERİLMELİDİR…").
            $headingOnly = $sectionOnly && collect(explode("\n", $documentText))->every(fn ($line) => trim($line) === '' || str_starts_with($line, '## '));
            $section = $headingOnly ? null : $this->kusurSection($documentText, $provider, inSection: $sectionOnly);
            // Seçilmiş bölümden kopyalanan kusur satırları doğrudan bulgu (kullanıcı kuralı: bölüm doğruysa içindekiler bulgudur);
            // ikinci ajan cümleyi bölüyordu (#122: "Not 1: … UYGUN DEĞİLDİR…" → yalnız "10.c.6.3.").
            // Madde işareti ("-PROJEYE ULAŞILAMADI.") bulgu metnine girmez.
            $copied = $headingOnly ? [] : ($sectionOnly && $section !== null ? array_values(array_filter(array_map(fn ($line) => ltrim(trim($line), "-* \t"), explode("\n", $section)), fn ($line) => $line !== '')) : null);
            if (!$headingOnly && $section === null && $sectionOnly && $wholeText !== null) {
                $documentText = $wholeText;
                $section = $this->kusurSection($documentText, $provider);
            }
            [$documentText, $sectionOnly] = $section !== null ? [$section, true] : [$documentText, false];
            $item = $sectionOnly
                ? ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]
                : ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'from' => ['type' => 'string', 'enum' => $sources]], 'required' => ['text', 'from']];
            $schema = ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => $item]], 'required' => ['items']];
        }

        // Boş liste "kusur yok" demektir (istek hatası ayrıca hata fırlatır); ana okumanın bulgularına geri dönülmez.
        $items = isset($copied) ? array_map(fn ($line) => ['text' => $line], $copied) : ($sectionOnly && trim($documentText) === '' ? [] : (array) (PkLocalRetry::client($provider, fn (array $r) => empty($r['items']) && ($semantic['extracted_data']['overall_result']['status'] ?? null) === 'uygun_degil')->extract($sectionOnly ? self::SECTION_PROMPT : self::PROMPT . ($measurementRows ? "\n" . self::MEASUREMENT_ROWS : ''), $documentText, 12000, schema: $schema)['items'] ?? []));
        $texts = collect($items)->filter(fn ($found) => $sectionOnly || in_array($found['from'] ?? null, ['kusur_bolumu', 'olcum_tablosu'], true))
            ->map(fn ($found) => (string) ($found['text'] ?? ''))->filter()->values()->all();
        $assigned = $this->assignSystems($texts, $semantic, $systems, $provider, $systemEquipment);
        // Ölçüm satırı bulgusunun sistemi satırın geldiği sistem tablosudur (ajana sorulmaz): eşleştirme ajanı menfez satırlarını fan
        // sistemine bağlıyordu (#141).
        foreach ($this->measurementFindings($measurementTable ?? [], $provider) as $text => $system) {
            if (!in_array($text, $texts, true)) {
                $assigned[count($texts)] = in_array($system, $systems, true) ? $system : null;
                $texts[] = $text;
            }
        }

        $equipment = $equipmentLinks ? $this->findingEquipment($texts, $provider) : [];
        $semantic['extracted_data']['findings'] = collect($texts)->map(fn (string $text, int $index) => [
            'id' => 'F' . ($index + 1),
            'system_name' => $assigned[$index] ?? null,
            'description' => $text,
            'source_pages' => [],
            'severity' => null,
            'ambiguous' => false,
        ] + ($equipment ? ['affected_equipment' => $equipment[$index] ?? []] : []))->all();

        // Mevcut talimatın 4. bölüm kuralı: sisteme atanmış uygunsuzluk bulgusu varsa sistem uygun değildir.
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $i => $system) {
            $own = array_keys(array_filter($assigned, fn ($name) => $name !== null && $name === ($system['system_name'] ?? null)));
            if ($own && ($system['verdict']['status'] ?? null) !== 'uygun_degil') {
                $semantic['template']['fire_systems']['systems'][$i]['verdict'] = ['status' => 'uygun_degil', 'raw' => null, 'basis' => 'derived', 'evidence' => $texts[$own[0]]];
            }
        }

        return $semantic;
    }

    // Ölçüm tablosu bulguları (havalandırma: sonucu uygun olmayan ölçüm satırı; uzman öyle kaydediyor). Satırlar numaralı, ajan
    // yalnız numarayı verir, bulgu satırın kendisi (hücreler raporda yazdığı gibi). Kusur bölümüyle aynı istekte küçük model kontrol
    // listesi maddelerini de kusur sanıyor, metni kurarken komşu satırın cihaz numarasını yazıyordu (#141: 11 yerine 12 NUMARA).
    // Bulgudaki sorunlu cihazların numaraları (bulgu index'i => ["3", "4"]). Ortak okuyucu bunları alır, kendi bulduklarıyla
    // birleştirir; ekipmana bağlama ve aynı no birden çok binadaysa konumla seçme PkInstallationReportReader'da. Ortak okuyucu
    // numarayı yalnız iki haneli ve kısaltmasız yazılmışsa buluyordu (#18: "YD-3", "YD-7", "YD118" dolaplara bağlanmıyordu).
    private function findingEquipment(array $texts, ?string $provider): array
    {
        if (!$texts) {
            return [];
        }
        $keys = array_map(fn ($index) => 'b' . $index, array_keys($texts));
        $found = PkLocalRetry::client($provider, fn () => false)->extract(
            self::EQUIPMENT_PROMPT,
            "BULGULAR:\n" . collect($texts)->map(fn (string $text, int $index) => $index . ': ' . $text)->implode("\n"),
            4000,
            schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => 'array', 'maxItems' => 60, 'items' => ['type' => 'string']]), 'required' => $keys]
        );

        return array_map(fn ($index) => array_values(array_unique(array_filter(array_map(fn ($no) => trim((string) $no), (array) ($found['b' . $index] ?? []))))), array_keys($texts));
    }

    private const EQUIPMENT_PROMPT = <<<'PROMPT'
Periyodik kontrol raporunun numaralı bulguları verilecek. HER bulgu için, bulgunun sorunlu olduğunu söylediği cihazların numaralarını yaz. Anahtar: b + bulgunun numarası; değer: numaralar listesi.
- Numarayı cihaz türünün kısaltması olmadan yaz: "YD-3" → "3", "H-12" → "12", "YD118" → "118".
- Birden çok cihaz sayılmışsa (örn. "YD-3,YD-4,YD-5", "YD-33-34-35") her numara ayrı öğe.
- Bulgu numarası yazılmış bir cihazdan söz etmiyorsa (örn. "dolap kabinleri yok", "dizel pompa çalışmıyor") boş liste.
- Bulgu iki numara arasında cihaz OLMADIĞINI söylüyorsa (örn. "YD-16 ile YD-72 arası yangın dolabı yok") o numaralar sorunlu cihaz değildir: boş liste.
- Madde / soru kodları (örn. "6.D.9.", "5.20") cihaz numarası değildir.
PROMPT;

    /** @return array<string, string> bulgu metni (satır) => satırın sistemi */
    private function measurementFindings(array $rows, ?string $provider): array
    {
        if (!$rows) {
            return [];
        }
        $numbered = collect($rows)->map(fn (array $row, int $i) => '[satır ' . ($i + 1) . '] ' . $row['line'])->implode("\n");
        $found = PkLocalRetry::client($provider, fn () => false)->extract(self::MEASUREMENT_TABLE, $numbered, 1000, schema: ['type' => 'object', 'properties' => [
            'rows' => ['type' => 'array', 'maxItems' => count($rows), 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => count($rows)]],
        ], 'required' => ['rows']]);

        return collect((array) ($found['rows'] ?? []))->map(fn ($n) => $rows[(int) $n - 1] ?? null)->filter()
            ->mapWithKeys(fn (array $row) => [trim($row['line'], ' |') => $row['system']])->all();
    }

    private const MEASUREMENT_TABLE = <<<'PROMPT'
Sana bir periyodik kontrol raporunun tablo satırları verilecek; her satırın başında [satır N] yazar. Ölçüm tablolarında (ölçüm noktası / menfez / mahal başına hız, debi, basınç gibi ölçülen değerler) sonucu "uygun değil" olan satırların numaralarını yaz (rows).
- Yalnız ölçüm satırları: kontrol listesi maddeleri (madde kodu, kriter / soru ve yanında U, U.D gibi işaret), cihaz listesi ve başlık satırları ölçüm satırı değildir; sonuçları ne olursa olsun yazma.
- Sonucu uygun olan satırları yazma. Uygun olmayan ölçüm satırı yoksa rows = [].
PROMPT;

    // Kusur bölümü ajanı: bölümün içeriği aynen ("" = bölüm var ama boş); raporda böyle bir bölüm yoksa null. $inSection: metin
    // zaten kusur bölümü diye seçilmiş (harita / Docling): başlık aranmaz, içindeki kusur maddeleri kopyalanır; madde yoksa null
    // (yanlış ya da boş bölüm: çağıran yedek metinde başlık kipiyle arar).
    private function kusurSection(string $documentText, ?string $provider, bool $inSection = false): ?string
    {
        if ($inSection) {
            $found = PkLocalRetry::client($provider, fn () => false)->extract(<<<'PROMPT'
Sana bir periyodik kontrol raporundan kusur / not bölümü olarak seçilmiş metin verilecek. Bu metinde, muayenede BU tesiste / ekipmanda bulunan kusur, uygunsuzluk ve eksiklik maddelerini raporda yazdığı gibi AYNEN kopyala; madde numarası / kodu varsa onunla birlikte, her madde ayrı satırda.
- Her raporda aynı yazılan genel mevzuat hatırlatmaları, kalıp açıklamalar, lejant, imza ve onay bilgileri kusur değildir; kopyalama.
- Kontrol listesi maddeleri (soru / kriter ve yanında sonucu) kusur bölümü değildir; kopyalama.
- section_exists: metinde en az bir kusur / eksiklik maddesi var mı?
- content: kopyaladığın maddeler; yoksa "".
PROMPT, $documentText, 3000, schema: ['type' => 'object', 'properties' => ['section_exists' => ['type' => 'boolean'], 'content' => ['type' => 'string']], 'required' => ['section_exists', 'content']]);

            return empty($found['section_exists']) || trim((string) ($found['content'] ?? '')) === '' ? null : trim((string) $found['content']);
        }
        $found = PkLocalRetry::client($provider, fn () => false)->extract(<<<'PROMPT'
Sana bir periyodik kontrol raporunun metni verilecek. Görevin raporun kusur / uygunsuzluk / eksiklik açıklamaları bölümünü bulmak.
- Bu bölümün başlığı firmaya göre değişir: "KUSUR AÇIKLAMALARI", "KUSUR AÇIKLAMALARI / NOTLAR", "UYGUNSUZLUKLAR", "TESPİT EDİLEN EKSİKLİKLER", "AÇIKLAMALAR" gibi; kusurlar yalnız "NOTLAR" başlığı altında da yazılabilir (tablonun içinde bir satır olarak da). Hemen ardından gelen "NOTLAR" bölümü de bu bölüme dahildir.
- section_exists: raporda böyle bir bölüm başlığı var mı?
- content: bölüm başlığından SONRA gelen metin, bir sonraki bölüm başlığına (örn. "SONUÇ VE KANAAT", "ONAY") kadar, raporda yazdığı gibi AYNEN. Başlığın kendisini ve başlıktan ÖNCE gelen satırları (kontrol listesi / kriter maddeleri) yazma. Başlık altında hiçbir şey yazmıyorsa content = "".
PROMPT, $documentText, 3000, schema: ['type' => 'object', 'properties' => ['section_exists' => ['type' => 'boolean'], 'content' => ['type' => 'string']], 'required' => ['section_exists', 'content']]);

        return empty($found['section_exists']) ? null : trim((string) ($found['content'] ?? ''));
    }

    // Bulguların sisteme bağlanması ayrı küçük istekle: bulgu metinleri + sistemler ve ekipman türleri (rapor metni gerekmez).
    // Dönüş: [bulgu sırası => sistem adı | null].
    private function assignSystems(array $texts, array $semantic, array $systems, ?string $provider, array $systemEquipment = []): array
    {
        if (!$texts || !$systems) {
            return [];
        }
        // Tek sistem (tek ekipman raporu): bütün bulgular onun; istek gerekmez.
        if (count($systems) === 1) {
            return array_fill(0, count($texts), $systems[0]);
        }
        $list = collect((array) ($semantic['template']['fire_systems']['systems'] ?? []))
            ->map(fn ($system) => '- ' . ($system['system_name'] ?? '') . (filled($system['section_heading_patterns'][0] ?? null) ? ' (rapordaki bölüm başlığı: "' . implode('", "', (array) $system['section_heading_patterns']) . '")' : '') . ' (ekipman: ' . (collect([...(array) ($systemEquipment[$system['system_name'] ?? ''] ?? []), ...collect((array) ($system['equipment_definitions'] ?? []))->pluck('equipment_name')->all()])->filter()->unique()->implode(', ') ?: '-') . ')')
            ->implode("\n");
        $numbered = collect($texts)->map(fn (string $text, int $index) => $index . ': ' . $text)->implode("\n");
        $schema = ['type' => 'object', 'properties' => ['assignments' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'index' => ['type' => 'integer'],
            'system_name' => ['type' => ['string', 'null'], 'enum' => [...$systems, null]],
        ], 'required' => ['index', 'system_name']]]], 'required' => ['assignments']];
        $found = PkLocalRetry::client($provider, fn (array $r) => empty($r['assignments']))->extract(<<<PROMPT
Bir tesisat periyodik kontrol raporunun bulguları ve sistemleri verilecek. Her bulgunun ait olduğu sistemi seç.
- ÖNCE madde koduna bak: bulgunun başında madde kodu varsa (örn. "6.G.5."), koddaki grup harfi (G) hangi sistemin bölüm başlığındaki kodla aynıysa (örn. "G HİDRANT") bulgu o sistemindir; metinde başka bir ekipman adı (örn. "dolap içerisinde") geçse bile.
- Bulgudaki ekipman adını sistemlerin ekipman listeleriyle karşılaştır (örn. bulguda pompa geçiyorsa ekipmanında pompa olan sistem). Bulgu hangi sistemin ekipmanını ya da bölümünü anlatıyorsa (örn. dolap, hidrant, pompa, depo, vana, boru, sprinkler, ölçüm noktası, pano) o sistemi seç. Bulgunun başındaki madde / bölüm kodu (örn. "7.B.3.") sistemi gösterir: kod, hangi sistemin rapordaki bölüm başlığının koduyla başlıyorsa bulgu o sisteme aittir.
- Bulguda numarasıyla / yeriyle bir cihaz geçiyorsa (örn. "YD-73 NOLU DOLAPTA …", "H-5 hidrantında …") bulgu o cihazın sistemine aittir; cümlenin devamındaki "sistemi" sözcüğü ya da cihazın bir parçasının adı başka bir sistemi göstermez.
- Bulgunun konusu sistemin adıyla / ekipmanıyla açıkça örtüşmüyorsa null (genel bulgu). Listede tek ya da az sistem var diye bulguyu ilgisiz bir sisteme bağlama; örneğin belge / kayıt sistemi yalnız belge, proje, kayıt eksikliği bulgularını alır.
- Bulgunun başında madde kodu varsa (örn. "6.D.9", "B.3"), koddaki grup harfi / numarası hangi sistemin bölüm başlığındaki kodla aynıysa (örn. "D Yangın Dolapları") bulgu o sistemindir.
- HER bulgu için bir kayıt yaz: index = bulgunun numarası.
SİSTEMLER:
{$list}
PROMPT, "BULGULAR:\n" . $numbered, 4000, schema: $schema);

        $assigned = [];
        foreach ((array) ($found['assignments'] ?? []) as $row) {
            $index = (int) ($row['index'] ?? -1);
            if (isset($texts[$index]) && in_array($row['system_name'] ?? null, $systems, true)) {
                $assigned[$index] = $row['system_name'];
            }
        }

        // Madde kodu → kontrol listesi grubu → sistem (kodlar PkFireAgents'tan): küçük model "6.G.5. DOLAP içerisinde…" bulgusunu
        // koddaki G (hidrant) yerine metindeki dolaba, harfsiz "5.20)" maddesini (B grubu, pompa) sprinklere bağlıyordu (#18, PK.239).
        // Bulgunun kodunu ayrı ajan okur; eşleme iki ajanın çıktısıyla: önce madde kodu, yoksa grup kodu.
        $norm = fn ($code) => mb_strtoupper(trim((string) $code, ' .)'), 'UTF-8');
        $owner = ['item' => [], 'group' => []];
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach (['item' => 'item_codes', 'group' => 'group_codes'] as $kind => $field) {
                foreach ((array) ($system[$field] ?? []) as $code) {
                    $owner[$kind][$code][] = $system['system_name'];
                }
            }
        }
        $owner = array_map(fn (array $codes) => array_filter($codes, fn (array $names) => count(array_unique($names)) === 1), $owner);
        if ($owner['item'] || $owner['group']) {
            $keys = array_map(fn ($index) => 'b' . $index, array_keys($texts));
            $codes = PkLocalRetry::client($provider, fn () => false)->extract(
                'Periyodik kontrol raporunun numaralı bulguları verilecek. HER bulgunun başındaki madde kodunu yaz: item = madde kodu, yazdığı gibi (örn. "6.G.5.", "5.20"); group = koddaki grup harfi (örn. "6.G.5." → "G", "B.3" → "B"), harf yoksa null. Bulgunun başında madde kodu yoksa ikisi de null. Anahtar: b + bulgunun numarası.',
                "BULGULAR:\n" . $numbered,
                3000,
                schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => 'object', 'properties' => ['item' => ['type' => ['string', 'null']], 'group' => ['type' => ['string', 'null']]], 'required' => ['item', 'group']]), 'required' => $keys]
            );
            foreach (array_keys($texts) as $index) {
                $found = (array) ($codes['b' . $index] ?? []);
                $system = $owner['item'][$norm($found['item'] ?? '')][0] ?? $owner['group'][$norm($found['group'] ?? '')][0] ?? null;
                if ($system !== null) {
                    $assigned[$index] = $system;
                }
            }
        }

        return $assigned + array_fill_keys(array_keys($texts), null);
    }
}
