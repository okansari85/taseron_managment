<?php

namespace App\Services\Ai\PkTakip;

use Illuminate\Support\Str;

/**
 * Elektrik ailesi (yerel model, Docling akışı): türün talimatı (PkElectricalReportAnalyzer) bölüm bölüm küçük ajanlara
 * verilir; kurallar aynen o talimattan. Sistemler (0, 2, 3) Docling'in özetinden (başlıklar ve tabloların ilk satırı: bir
 * bölümün kendi formu / tablosu var mı burada görünür); ölçüm / cihaz listeleri sistem başına tablo haritasıyla (4. bölüm
 * adlandırma kuralıyla; yapıyı PkTableDefinitions bulur, satırları mevcut tablo okuyucu okur); tesis bilgisi ve lejant (6, 7).
 * Rapor bilgisi / sonuç / kuruluş (PkHeaderPass) ve bulgular (PkFindingsPass) ayrı ajanlardır. Sözleşme aynı.
 */
class PkElectricalAgents
{
    public function __construct(private PkGeminiReportClient $contract, private PkTableDefinitions $definitions)
    {
    }

    /**
     * @param string $instruction türün talimatı
     * @param array $parts PkDocumentSections::texts() (by_system: sistem adı => o sistemin bölümleri; facility)
     * @param list<array> $doclingTables pk_docling.py "tables"
     */
    public function read(string $instruction, array $parts, array $doclingTables, string $pdfPath, array $tableLabels, string $typeSlug, ?string $provider = null, array $systemNames = []): array
    {
        $schema = $this->contract->responseSchema()['properties'];
        $template = $schema['template']['properties'];
        $data = $schema['extracted_data']['properties'];
        $system = $template['fire_systems']['properties']['systems']['items']['properties'];

        // Docling'in bölümlerinde hiçbir sistemin tablosu yoksa (başlıklar tablonun içinde kaldı) tablonun sistemini ajan Camelot satırlarından
        // seçer; alanı, haritası ve okuyucusu aynı kalır.
        if (!collect((array) ($parts['by_system'] ?? []))->contains(fn ($text, $name) => $name !== 'Tesisat Geneli' && str_contains((string) $text, '### Tablo '))) {
            [$bySystem, $camelotTables, $labels] = $this->systemTables($pdfPath, $systemNames, self::sections($instruction, [2], false), $provider);
            if ($bySystem) {
                $parts['by_system'] = [...(array) ($parts['by_system'] ?? []), ...$bySystem];
                $listOnly = array_keys($bySystem);
                $doclingTables = $camelotTables;
                $tableLabels = array_values(array_unique([...$tableLabels, ...$labels]));
            }
        }

        // Her sistem ayrı ajan, yalnız kendi bölümü: sonucu (3. bölüm kuralı) ve ölçüm / cihaz listesi (4. bölüm, tablo haritası).
        $systems = collect();
        $map = [];
        foreach ((array) ($parts['by_system'] ?? []) as $name => $text) {
            $verdict = PkLocalRetry::client($provider, fn () => false)->extract(
                self::sections($instruction, [3], false) . "\n\nBU ADIMDA: verilen bölüm \"{$name}\" sistemine ait; yalnız bu sistemin sonucunu (verdict) yaz.",
                $text,
                2000,
                schema: ['type' => 'object', 'properties' => ['verdict' => $system['verdict']], 'required' => ['verdict']]
            )['verdict'] ?? null;
            // Kullanıcı kuralı: bir kriter uygunsuzsa sistem uygunsuz. Sonuç ajanı boş bıraktıysa satır satır ajan kararı: tek soruda satırdaki "U.D"yi kaçırıyordu (SGS topraklama).
            if (blank($verdict['status'] ?? null) && $this->anyRowFailed($text, $provider)) {
                $verdict = ['status' => 'uygun_degil', 'raw' => null, 'basis' => 'derived', 'evidence' => null];
            }
            $lines = explode("\n", $text);
            $systems[$name] = [
                'system_name' => $name,
                'section_heading_patterns' => array_values(array_unique(array_map(fn ($line) => substr($line, 3), array_filter($lines, fn ($line) => str_starts_with($line, '## '))))),
                'verdict' => $verdict ?? ['status' => null, 'raw' => null, 'basis' => 'not_stated', 'evidence' => null],
            ];
            $tableNos = array_values(array_filter(array_map(fn ($line) => str_starts_with($line, '### Tablo ') ? (int) substr($line, 10) : 0, $lines)));
            // Kontrol listesi ve yazı tablosu ekipman listesi değildir (kriter ekipman olmaz): haritaya girince 11 sahte satır çıkıyordu (SGS topraklama).
            if ($name !== 'Tesisat Geneli' && $tableNos && !in_array($name, $listOnly ?? [], true)) {
                $found = $this->tableKinds($text, $tableNos, $provider);
                $tableNos = array_values(array_filter($tableNos, fn (int $no) => !in_array($found['t' . $no] ?? null, ['kontrol_listesi', 'yazi'], true)));
            }
            if ($name !== 'Tesisat Geneli' && $tableNos) {
                $map[$name] = app(PkTableMap::class)->map($text, [$name => []], $tableNos, $tableLabels, $provider, self::sections($instruction, [4], false), in_array($name, $listOnly ?? [], true))[$name] ?? ['lists' => [], 'singles' => []];
            }
        }
        // Özelliği olmayan tek kayıt ekipman değildir: kontrol maddesi "Uygulaması Yok" kimliğiyle ekipman oluyordu (Neta paratoner).
        foreach ($map as $name => $found) {
            $map[$name]['singles'] = array_values(array_filter((array) ($found['singles'] ?? []), fn (array $single) => collect((array) ($single['properties'] ?? []))->contains(fn ($pair) => is_array($pair) && filled($pair['value'] ?? null))));
        }
        $definitions = $this->definitions->build($pdfPath, $doclingTables, $map);
        foreach ($definitions as $name => $list) {
            $definitions[$name] = array_values(array_filter($list, fn (array $definition) => ($definition['instance_structure']['equipment_axis'] ?? null) !== 'rows'
                || $this->definitions->identityLeads($pdfPath, (string) ($definition['instance_structure']['identity_field'] ?? ''))));
        }
        foreach ($map as $name => $found) {
            $list = ((array) ($found['lists'] ?? []))[0] ?? null;
            if (empty($definitions[$name]) && $list && $this->definitions->identityLeads($pdfPath, (string) ($list['identity_label'] ?? '')) && ($definition = $this->definitions->fromHeader($pdfPath, (string) $list['equipment_name'], (string) ($list['identity_label'] ?? '')))) {
                $definitions[$name] = [$definition];
            }
        }
        // Harita aynı listeyi ikinci adla ya da yanlış kimlikle de verebiliyor (378300-topraklama: 36 satır 72 oldu; 96320: "Not 6" satırları).
        // shortcut: sistem başına tek liste; bir sistemde ayrı başlıklı iki ölçüm listesi olan rapor çıkarsa liste başına tarif kurulmalı.
        foreach ($listOnly ?? [] as $name) {
            $definitions[$name] = array_slice($definitions[$name] ?? [], 0, 1);
        }

        // Tesis bilgisi bölümü etiketlenmediyse bütün yapılı metin (Tesis Özellikleri kartı her raporda okunur).
        $facilityText = $parts['facility'] ?? $parts['all'] ?? null;
        $facility = $this->facilityFromRows($pdfPath, $provider) ?? $this->facility(self::sections($instruction, [6, 7]), $facilityText, $provider);
        $found =['template_type' => 'tesisat_raporu']; // shortcut: tek bölüm raporu (sistem_raporu) ayrımı yok; gerekirse kapsam ajanı eklenir

        return [
            'template' => [
                'template_type' => $found['template_type'] ?? null,
                'template_version' => '1.0',
                'report_information' => [],
                'facility_or_project_information' => $facility['facility_or_project_information'] ?? null,
                'fire_systems' => ['systems' => $systems->map(fn (array $row, string $name) => $row + [
                    'section_detection' => ['start_heading_patterns' => [], 'continuation_patterns' => [], 'end_detection_patterns' => []],
                    'equipment_definitions' => $definitions[$name] ?? [],
                ])->values()->all()],
            ],
            'extracted_data' => [
                'report_category' => null,
                'extraction_mode' => 'structured',
                'equipment_type' => ['slug' => $typeSlug, 'evidence' => null],
                'equipment_tag' => ['label' => null, 'value' => null, 'evidence' => null],
                'equipment_specs' => [],
                'report_information' => [],
                'facility_information' => (array) ($facility['facility_information'] ?? []),
                'result_legend' => (array) ($facility['result_legend'] ?? []),
                'findings' => [],
                'overall_result' => null,
                'inspection_body' => null,
            ],
        ];
    }

    /**
     * Tesis / proje bilgisi ajanı (elektrik ve yangın tesisatı ortak): kural metni türün talimatından. Kart yalnız ilk etiketi
     * kullanır (PkElectricalReportReader::facilityValues); alan × etiket döngüsü sınırlanır (NuExtract3 #128 / #129: "Bina
     * Yüksekliği" tekrarı cevap sınırını aştı).
     *
     * @return array{facility_or_project_information?: array, facility_information?: array, result_legend?: array}
     */
    public function facility(string $rules, ?string $text, ?string $provider = null): array
    {
        if (blank($text)) {
            return [];
        }
        $schema = $this->contract->responseSchema()['properties'];
        // Etiket ve değer aynı kayıtta: ayrı listelerde (template alanları + extracted_data değerleri) küçük model etiketleri
        // yazıp bütün değerleri null bırakıyordu (NuExtract3 #141: "Kullanım Amacı | ASPİRASYON / İKLİMLENDİRME" boş).
        $found = PkLocalRetry::client($provider, fn () => false)->extract(
            $rules . "\n\nBU ADIMDA: yalnız tesis / proje bilgileri ve sonuç lejantı (result_legend). section_heading_patterns = tesis bilgileri bölümünün başlıkları. fields: raporda GERÇEKTEN bulunan her alan için key, label (PDF'deki etiket AYNEN) ve value (o etiketin yanında / altında yazan değer AYNEN; boşsa null). kind: alanın türü, anlamına göre: tesis = tesise / projeye ait bilgi; kriter = kontrol listesi / test maddesi ya da grup başlığı (soru biçiminde olabilir, sonucu U, U.D, uygun, uygun değil gibi işarettir); rapor = rapor no, rapor / muayene tarihi, müşteri, adres, muayene kuruluşu ve yöntemi.",
            $text,
            4000,
            schema: ['type' => 'object', 'properties' => [
                'section_heading_patterns' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string']],
                // 50: yangın proje kartında Proje / Uygulama çiftleri 30'u aşıyor (#18); döngüde tekrar edenleri aşağıda unique atar.
                'fields' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object', 'properties' => [
                    'key' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    'value' => ['type' => ['string', 'null']],
                    'kind' => ['type' => 'string', 'enum' => ['tesis', 'kriter', 'rapor']],
                ], 'required' => ['key', 'label', 'value', 'kind']]],
                'result_legend' => $schema['extracted_data']['properties']['result_legend'],
            ], 'required' => ['section_heading_patterns', 'fields', 'result_legend']]
        );
        $fields = collect((array) ($found['fields'] ?? []))->filter(fn ($field) => is_array($field) && filled($field['key'] ?? null) && ($field['kind'] ?? null) === 'tesis')->unique('key')->values();

        return [
            'facility_or_project_information' => [
                'section_heading_patterns' => (array) ($found['section_heading_patterns'] ?? []),
                'fields' => $fields->map(fn (array $field) => ['key' => $field['key'], 'label_patterns' => array_filter([(string) ($field['label'] ?? '')])])->all(),
            ],
            'facility_information' => $fields->map(fn (array $field) => ['key' => $field['key'], 'value' => $field['value'] ?? null])->all(),
            'result_legend' => (array) ($found['result_legend'] ?? []),
        ];
    }

    /**
     * Tablo türü, ajan kararı: "### Tablo N" ile numaralı tablolar.
     *
     * @param list<int> $nos
     * @return array<string, string> tN => tür
     */
    private function tableKinds(string $text, array $nos, ?string $provider): array
    {
        $keys = array_map(fn (int $no) => 't' . $no, $nos);

        return (array) PkLocalRetry::client($provider, fn () => false)->extract(<<<'PROMPT'
Sana bir periyodik kontrol raporunun tabloları verilecek ("### Tablo N" ve ilk satırları). HER tablonun türünü yaz (anahtar: tN).
- olcum_listesi: her satırı ayrı bir ölçüm noktası ya da ekipman (pano, röle, priz gibi) olan, aynı sütunlarda sayısal değerler taşıyan liste. Sıra no ile devam eden, başlığı önceki sayfada kalmış satırlar da budur. Tablonun üstünde başka satırlar olsa da içinde böyle bir liste başlıyorsa budur.
- kontrol_listesi: kontrol maddeleri ve sonuçları (UYGUN, UYGUN DEĞİL, U, U.D, Uygulama Yok gibi).
- bilgi: rapor no, tarih, firma, tesis, ölçüm aleti, yetkili kişi bilgisi (etiket ve değer; tek kayıtlık tablo).
- yazi: not, açıklama, sonuç cümlesi.
PROMPT, $text, max(500, count($nos) * 15), schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => 'string', 'enum' => ['olcum_listesi', 'kontrol_listesi', 'bilgi', 'yazi']]), 'required' => $keys]);
    }

    // Bölümün tablo satırlarından en az birinin sonucu uygun değil mi: her satır için ajan kararı. shortcut: ilk 200 satır, daha uzun kontrol listesi çıkarsa bölünür.
    private function anyRowFailed(string $text, ?string $provider): bool
    {
        $rows = array_values(array_unique(array_map(
            fn (string $line) => implode(' | ', array_unique(array_filter(explode(' | ', trim(substr($line, 2, -2))), fn (string $cell) => trim($cell) !== ''))),
            array_slice(array_values(array_filter(explode("\n", $text), fn (string $line) => str_starts_with($line, '| '))), 0, 200)
        )));
        if (!$rows) {
            return false;
        }
        $keys = array_map(fn (int $i) => 's' . ($i + 1), array_keys($rows));
        $found = (array) PkLocalRetry::client($provider, fn () => false)->extract(<<<'PROMPT'
Sana bir periyodik kontrol raporunun tablo satırları verilecek; her satırın başında [sN] yazar. HER satırın sonucunu yaz (anahtar: sN).
- uygun: madde uygun (U, UYGUN, ✓).
- uygun_degil: madde uygun değil (U.D, UYGUN DEĞİL, UYGUNSUZ).
- uygulanamaz: N.U, U.Y, Uygulama Yok, Görülemedi.
- diger: başlık, bilgi ya da sonucu olmayan satır.
PROMPT, implode("\n", array_map(fn (string $key, string $row) => "[{$key}] {$row}", $keys, $rows)), max(500, count($rows) * 12), schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => 'string', 'enum' => ['uygun', 'uygun_degil', 'uygulanamaz', 'diger']]), 'required' => $keys]);

        return in_array('uygun_degil', $found, true);
    }

    /**
     * Her çizgili tablonun sistemi, tablonun başından tek kararla (satırları ajan okumaz). Katalogda karşılığı olmayan tablo rapordaki
     * adıyla kalır: okunur, görünür, kaydedilmez. Dönen alanlar Camelot'un kendi kutusu, tablo haritası bunlarla çalışır.
     *
     * @param list<string> $names katalog sistemleri
     * @return array{0: array<string, string>, 1: list<array>, 2: list<string>} sistem => metin, tablo alanları, sütun başlıkları
     */
    private function systemTables(string $pdfPath, array $names, string $rules, ?string $provider): array
    {
        $tables = self::latticeTables((array) (app(PkCachedCamelot::class)->extract($pdfPath)['tables'] ?? []));
        if (!$tables) {
            return [[], [], []];
        }
        $text = collect($tables)->map(fn (array $table) => '### Tablo ' . $table['no'] . "\n" . implode("\n", array_slice($table['rows'], 0, 12)))->implode("\n\n");
        $keys = array_map(fn (array $table) => 't' . $table['no'], $tables);
        // İki ayrı karar: önce tablo ölçüm listesi mi, yalnız öyleyse hangi sistem. Tek kararda hiç ölçüm tablosu olmayan rapora sistem açıyordu
        // (kör test, 11930-elektrik: kontrol listesi ve notlardan 5 sahte sistem).
        $kinds = $this->tableKinds($text, array_column($tables, 'no'), $provider);
        $runs = self::listRuns($tables, array_keys(array_filter((array) $kinds, fn ($kind) => $kind === 'olcum_listesi')));
        if (!$runs) {
            return [[], [], []];
        }
        $keys = array_map(fn (int $i) => 'l' . ($i + 1), array_keys($runs));
        $text = collect($runs)->map(fn (array $run, int $i) => '### Liste ' . ($i + 1) . "\n" . implode("\n", array_slice(array_merge(...array_column($run, 'rows')), 0, 20)))->implode("\n\n");
        $found = PkLocalRetry::client($provider, fn () => false)->extract(<<<PROMPT
Sana bir periyodik kontrol raporunun ölçüm / ekipman listeleri verilecek ("### Liste N" ve ilk satırları; listenin üstünde başlığın durduğu tablo da görünür). HER listenin sistemini yaz (anahtar: lN).
- system: listenin ait olduğu sistem, listeden AYNEN, sütunlarına bakarak. Listede karşılığı yoksa "katalogda_yok".
- name: yalnız "katalogda_yok" iken listenin rapordaki adı; diğerlerinde null.

SİSTEM KURALI:
{$rules}
PROMPT, $text, max(1000, count($runs) * 40), schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => 'object', 'properties' => [
            'system' => ['type' => 'string', 'enum' => [...$names, 'katalogda_yok']],
            'name' => ['type' => ['string', 'null']],
        ], 'required' => ['system', 'name']]), 'required' => $keys]);

        $bySystem = [];
        $labels = [];
        foreach ($runs as $i => $run) {
            $choice = (array) ($found['l' . ($i + 1)] ?? []);
            $system = ($choice['system'] ?? null) === 'katalogda_yok' ? trim((string) ($choice['name'] ?? '')) : (string) ($choice['system'] ?? '');
            // Katalogda olmayan liste ancak gerçek listeyse: 3 satırlık cihaz tablosu öndeki firma tablosunun adıyla ("Ünvanı | ARÇELİK…") sahte sistem oluyordu (Neta
            // elektrik; kataloğa bağlanan en kısa gerçek liste SGS'de başlık + 4 satır).
            $own = array_sum(array_map(fn (array $table) => ($kinds['t' . $table['no']] ?? null) === 'olcum_listesi' ? count($table['rows']) : 0, $run));
            if ($system === '' || (($choice['system'] ?? null) === 'katalogda_yok' && $own < 5)) {
                continue;
            }
            // Ajana yalnız tablonun başı: bütün satırları verince tek tek yazıyordu (378300-topraklama: 200 sn); satırları tablo okuyucu okur.
            foreach ($run as $k => $table) {
                $bySystem[$system] = ($bySystem[$system] ?? '') . "\n### Tablo " . $table['no'] . "\n" . implode("\n", array_map(fn (string $row) => '| ' . $row . ' |', $k < 2 ? array_slice($table['rows'], 0, 12) : array_slice($table['rows'], 0, 1)));
                $labels = [...$labels, ...$table['labels']];
            }
        }

        return [array_map('trim', $bySystem), array_map(fn (array $table) => ['no' => $table['no'], 'page' => $table['page'], 'area' => $table['area']], $tables), array_values(array_unique($labels))];
    }

    /**
     * Ölçüm listeleri: art arda gelen aynı sütun sayılı tablolar tek liste (başlıksız devam sayfaları); listenin önündeki tablo da eklenir,
     * başlık orada olabilir (topraklama: "Sıra | Ölçüm Noktası" kriter satırlarının altında, satırlar sonraki sayfalarda).
     *
     * @param list<string> $listKeys ölçüm listesi denen tablolar ("t3")
     * @return list<list<array>>
     */
    public static function listRuns(array $tables, array $listKeys): array
    {
        $runs = [];
        $columns = null;
        foreach ($tables as $i => $table) {
            if (!in_array('t' . $table['no'], $listKeys, true)) {
                $columns = null;
                continue;
            }
            if ($columns === $table['columns']) {
                $runs[count($runs) - 1][] = $table;
                continue;
            }
            $runs[] = isset($tables[$i - 1]) && !in_array('t' . $tables[$i - 1]['no'], $listKeys, true) ? [$tables[$i - 1], $table] : [$table];
            $columns = $table['columns'];
        }

        // 3 satırdan azı liste değildir: tek kayıtlık bilgi tablosu liste sanılıp sahte sistem açıyordu (#126: "Rapor No" tablosu; gerçek listeler 15+ satır).
        return array_values(array_filter($runs, fn (array $run) => count(array_merge(...array_map(fn (array $table) => in_array('t' . $table['no'], $listKeys, true) ? $table['rows'] : [], $run))) >= 3));
    }

    /**
     * Çizgili tablolar: numara, sayfa, Camelot kutusu (sol-üst → sağ-alt), dolu satırlar ve sütun başlığı adayları (ölçüm tablosunun
     * başlığı kriter satırlarının altında olabilir, sayfa başında değil).
     *
     * @return list<array{no: int, page: int, area: string, rows: list<string>, labels: list<string>}>
     */
    public static function latticeTables(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            if (!is_array($table) || ($table['flavor'] ?? null) !== 'lattice' || count((array) ($table['bbox'] ?? [])) !== 4) {
                continue;
            }
            [$x1, $y1, $x2, $y2] = array_map('floatval', $table['bbox']);
            $rows = array_values(array_filter(array_map(fn ($row) => array_values(array_filter(array_map(fn ($cell) => Str::squish((string) $cell), (array) $row), fn (string $cell) => $cell !== '')), (array) ($table['data'] ?? []))));
            $out[] = [
                'no' => count($out) + 1,
                'page' => (int) ($table['page'] ?? 0),
                'area' => implode(',', [min($x1, $x2), max($y1, $y2), max($x1, $x2), min($y1, $y2)]),
                'columns' => count((array) (((array) ($table['data'] ?? []))[0] ?? [])),
                'rows' => array_map(fn (array $cells) => implode(' | ', $cells), $rows),
                'labels' => array_values(array_unique(array_filter(array_merge(...array_map(fn (array $cells) => array_filter($cells, fn (string $cell) => mb_strlen($cell) <= 60), $rows ?: [[]])), fn (string $cell) => preg_match('/\p{L}/u', $cell) === 1))),
            ];
        }

        return $out;
    }

    /**
     * Tesis kartı, Camelot satırlarından iki ayrı kararla (kör test, 31 Eki): önce her satırın türü, sonra yalnız tesis satırlarının
     * etiket-değer çiftleri, satır satır. Düz metinden hepsini birden isteyince değerler kayıyordu ("proje var mı = U", enerji
     * kuruluşu = firma adı). Çizgili tablo ya da tesis satırı yoksa null: çağıran eski ajana düşer.
     */
    private function facilityFromRows(string $pdfPath, ?string $provider): ?array
    {
        $cells = self::facilityCells((array) (app(PkCachedCamelot::class)->extract($pdfPath)['tables'] ?? []));
        $rows = array_column($cells, 'row');
        if (!$rows) {
            return null;
        }
        $keys = array_map(fn (int $i) => 's' . ($i + 1), array_keys($rows));
        // Önce tablo konusu, bütün tablolar yan yana (karşılaştırarak karar verir); satır satır karar yalnız karışık tabloda. Tek istekte bütün satırlarda
        // etiket-değer satırlarına "kriter" diyordu, tablo tablo sorunca firma bilgisine "tesis" (Neta yangın algılama).
        $groups = collect($cells)->groupBy('table', preserveKeys: true);
        $topicKeys = $groups->keys()->map(fn ($table) => 't' . $table)->all();
        $topics = (array) PkLocalRetry::client($provider, fn () => false)->extract(<<<'PROMPT'
Sana bir periyodik kontrol raporunun tabloları verilecek ("### Tablo tN" ve ilk satırları). HER tablonun konusunu yaz (anahtar: tN).
- tesis: tesisin / projenin özellikleri (enerji sağlayan kuruluş, şebeke tipi, proje var mı, kullanım amacı, yapı cinsi, tesise özgü bilgiler gibi etiket ve değerleri).
- rapor_firma: rapor no, tarihler, firma / müşteri, adres, telefon, sözleşme ve sicil no.
- alet: ölçüm aleti, cihaz, kalibrasyon.
- kontrol_listesi: kontrol maddeleri ve sonuçları.
- olcum_listesi: ölçüm noktası ya da ekipman satırları.
- yazi: not, açıklama, sonuç, yönetmelik başlığı.
- imza: yetkili kişi, personel, onay.
- karisik: aynı tabloda birden çok konu birlikte (tesis özellikleri ile ölçüm aleti ya da kontrol listesi gibi).
PROMPT, $groups->map(fn ($group, $table) => "### Tablo t{$table}
" . $group->take(8)->map(fn (array $cell) => mb_substr($cell['row'], 0, 160))->implode("
"))->implode("

"), max(500, count($topicKeys) * 15), schema: ['type' => 'object', 'properties' => array_fill_keys($topicKeys, ['type' => 'string', 'enum' => ['tesis', 'rapor_firma', 'alet', 'kontrol_listesi', 'olcum_listesi', 'yazi', 'imza', 'karisik']]), 'required' => $topicKeys]);
        $kinds = [];
        foreach ($groups as $table => $group) {
            $topic = $topics['t' . $table] ?? null;
            // shortcut: tablonun ilk 40 satırı; tesis bilgisi uzun listenin sonunda olan rapor çıkarsa artırılır.
            $own = array_slice($group->keys()->all(), 0, 40);
            // Uzun tablo tek konu olamaz (form bütün sayfayı tek tablo çiziyor: Neta akü, 59 satır): satır kararına gider.
            if ($topic === 'tesis' && count($own) <= 15) {
                foreach ($own as $i) {
                    $kinds[$keys[$i]] = 'tesis';
                }
            }
            if ($topic !== 'karisik' && !($topic === 'tesis' && count($own) > 15)) {
                continue;
            }
            $ownKeys = array_map(fn (int $i) => $keys[$i], $own);
            $numbered = array_map(fn (int $i) => "[{$keys[$i]}] " . mb_substr($rows[$i], 0, 200), $own);
            $kinds += (array) PkLocalRetry::client($provider, fn () => false)->extract(<<<'PROMPT'
Sana bir periyodik kontrol raporunun tablo satırları verilecek; her satırın başında [sN] yazar, hücreler | ile ayrılır. HER satırın türünü anlamına göre yaz (anahtar: sN).
- tesis: tesisin / projenin özelliği: enerji sağlayan kuruluş, şebeke tipi ve gerilimi, proje var mı, projeye uygunluk, topraklama / topraklayıcı şekli, kontrol nedeni, yapı cinsi, kullanım amacı ve yeri, son kontrol tarihi, tesisatta değişiklik. Satırın tek bir hücresi bile böyleyse satır tesistir, yanında U gibi sonuç olsa da.
- kriter: kontrol listesi tablosunun maddesi ve sonucu (A.1. gibi madde numaralı ya da "KONTROL KRİTERİ / Değerlendirme" tablosundaki UYGUN, UYGUN DEĞİL, U, U.D, U.Y), madde grubunun başlığı, sütun başlığı. Değeri böyle bir sonuç olmayan etiket-değer satırı kriter değildir.
- rapor: rapor no, tarihler, firma / müşteri, adres, telefon, e-posta, sicil ve sözleşme no, kontrol metodu ve kapsamı, kişi, imza, kuruluş bilgisi.
- alet: ölçüm aleti ya da termal kamera, seri no, kalibrasyon.
- olcum: ölçüm noktası ya da ekipman listesinin satırı (sıra no, yer adı ve sayısal değerler: menfez, fan, pano, priz gibi) ya da böyle bir listenin sütun başlığı.
- baslik: yalnız bölüm başlığı olan satır.
- diger: not, sonuç cümlesi, açıklama.
PROMPT, implode("\n", $numbered), max(500, count($own) * 12), schema: ['type' => 'object', 'properties' => array_fill_keys($ownKeys, ['type' => 'string', 'enum' => ['tesis', 'kriter', 'rapor', 'alet', 'olcum', 'baslik', 'diger']]), 'required' => $ownKeys]);
        }

        $fields = [];
        foreach ($rows as $i => $row) {
            if (($kinds[$keys[$i]] ?? null) !== 'tesis') {
                continue;
            }
            $found = PkLocalRetry::client($provider, fn () => false)->extract(<<<'PROMPT'
Sana bir rapor tablosunun TEK satırı verilecek; hücreler | ile ayrılır. Satırdaki HER etiketi ve değerini yaz; etiket ve değer aynı hücrede de olabilir.
- label: etiket, satırda yazdığı gibi.
- value: o etikete ait değer, satırda yazdığı gibi. Seçenekli alanda değer yalnız ☒ işaretli seçenektir (işaretin kendisi yazılmaz); ☐ işaretli seçenek değer değildir. Değer yoksa null.
PROMPT, $row, 1000, schema: ['type' => 'object', 'properties' => ['fields' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'object', 'properties' => [
                'label' => ['type' => 'string'],
                'value' => ['type' => ['string', 'null']],
            ], 'required' => ['label', 'value']]]], 'required' => ['fields']]);
            foreach ((array) ($found['fields'] ?? []) as $field) {
                $pair = is_array($field) ? self::facilityPair($row, (string) ($field['label'] ?? ''), (string) ($field['value'] ?? '')) : null;
                if ($pair) {
                    $fields[] = $pair;
                }
            }
        }
        if (!$fields) {
            return null;
        }
        $keyed = array_map(fn (array $pair) => ['key' => Str::slug($pair['label'], '_'), ...$pair], $fields);

        return [
            'facility_or_project_information' => [
                'section_heading_patterns' => [],
                'fields' => collect($keyed)->unique('key')->map(fn (array $field) => ['key' => $field['key'], 'label_patterns' => [$field['label']]])->values()->all(),
            ],
            'facility_information' => array_map(fn (array $field) => ['key' => $field['key'], 'value' => $field['value']], $keyed),
            'result_legend' => [],
        ];
    }

    /**
     * Kartın aranacağı satırlar: çizgili tabloların ilk sayfalardaki dolu satırları, hücreler " | " ile.
     * shortcut: yalnız ilk 3 sayfa (tesis bilgileri raporun başında), kapak ve içindekiler daha uzun bir raporda çıkarsa artırılır.
     *
     * @return list<string>
     */
    public static function facilityRows(array $tables, int $pages = 3): array
    {
        return array_column(self::facilityCells($tables, $pages), 'row');
    }

    /** @return list<array{row: string, table: int}> */
    public static function facilityCells(array $tables, int $pages = 3): array
    {
        $rows = [];
        foreach ($tables as $index => $table) {
            if (!is_array($table) || ($table['flavor'] ?? null) !== 'lattice' || (int) ($table['page'] ?? 0) > $pages) {
                continue;
            }
            foreach ((array) ($table['data'] ?? []) as $row) {
                $cells = array_values(array_filter(array_map(fn ($cell) => Str::squish((string) $cell), (array) $row), fn (string $cell) => $cell !== ''));
                // Her sayfada tekrar eden form satırı bir kez (Neta akü: aynı üst bilgi 3 sayfada).
                if ($cells && !in_array(implode(' | ', $cells), array_column($rows, 'row'), true)) {
                    $rows[] = ['row' => implode(' | ', $cells), 'table' => $index];
                }
            }
        }

        return $rows;
    }

    // Etiket ve değer satırda gerçekten yazılı olmalı (model uyduramaz); değeri boş alan karta girmez.
    public static function facilityPair(string $row, string $label, string $value): ?array
    {
        // Yalnız harf ve rakam karşılaştırılır: PDF'te bozuk harf birleşimi metinde boşluk ya da garip işaret olarak kalır ("tesisatı" → "tesisa  ", #126).
        $plain = fn (string $text) => mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text), 'UTF-8');
        [$label, $value] = [Str::squish($label), Str::squish(str_replace(['☒', '☐'], '', $value))];
        if ($label === '' || $value === '' || !str_contains($plain($row), $plain($label)) || !str_contains($plain($row), $plain($value))) {
            return null;
        }

        return ['label' => $label, 'value' => $value];
    }

    // Talimatın istenen numaralı bölümleri (başlık satırı "N. "; alt bölümler "4a." gibi bir öncekinin içinde kalır);
    // $common: ilk bölümden önceki ortak kısım (sabit kararlar) da eklenir.
    public static function sections(string $instruction, array $numbers, bool $common = true): string
    {
        $out = [];
        $keep = $common;
        foreach (explode("\n", $instruction) as $line) {
            if (preg_match('/^(\d{1,2})\. /', $line, $match)) {
                $keep = in_array((int) $match[1], $numbers, true);
            }
            if ($keep) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }
}
