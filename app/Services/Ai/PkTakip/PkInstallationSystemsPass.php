<?php

namespace App\Services\Ai\PkTakip;

use ReflectionMethod;

/**
 * Kısa talimat açıkken (yerel model) elektrik dışı tesisat raporlarında (yangın tesisatı gibi) sistemlerin ikinci okuması:
 * küçük model uzun talimatla yalnız bir iki sistem yazıyor. Önce katalogdaki sistemlerden raporda bulunanlar ve sonuçları
 * sorulur; ana okumanın ekipman tarifleri ilgili sisteme bağlanır; katalogda olup tarif edilmemiş ekipmanlar her sistem
 * için ayrı istekle sorulur. Talimat bölümleri (4, 5) ve şema mevcut analizörden birebir alınır; JSON sözleşmesi aynıdır.
 */
class PkInstallationSystemsPass
{
    private const STATUSES = ['uygun', 'uygun_degil', 'uygulanamiyor'];

    public function __construct(private PkReportAnalyzer $analyzer)
    {
    }

    // $catalog: PkInstallationReportReader::promptCatalog() (tesisat türleri, sistemleri ve ekipman türleri).
    // $typeSlug: tür biliniyorsa (elektrik ailesi); yoksa ana okumanın seçtiği tür.
    public function apply(array $semantic, string $documentText, array $catalog, ?string $provider = null, ?string $typeSlug = null, ?string $structuredText = null, array $tableLabels = [], array $doclingTables = [], ?string $pdfPath = null): array
    {
        $type = collect($catalog)->firstWhere('slug', $typeSlug ?? ($semantic['extracted_data']['equipment_type']['slug'] ?? null));
        $catalogSystems = collect((array) ($type['systems'] ?? []))->map(fn ($system) => is_array($system) ? $system : ['name' => (string) $system, 'equipment' => []]);
        if ($catalogSystems->isEmpty()) {
            return $semantic;
        }
        $read = collect((array) ($semantic['template']['fire_systems']['systems'] ?? []))->filter(fn ($system) => is_array($system))->values();
        $readNames = $read->pluck('system_name')->filter()->values()->all();
        $prompt = (string) (new ReflectionMethod(PkReportAnalyzer::class, 'systemPrompt'))->invoke($this->analyzer);

        // Docling akışı: sistemler kontrol listesi gruplarından, ekipman tarifleri sistem başına kendi tablolarından (PkFireAgents).
        if ($doclingTables && $pdfPath) {
            $equipment = $catalogSystems->mapWithKeys(fn ($system) => [$system['name'] => collect((array) ($system['equipment'] ?? []))->pluck('name')->all()])->all();
            $systems = app(PkFireAgents::class)->systems($structuredText ?? $documentText, $doclingTables, $pdfPath, $tableLabels, $equipment, (string) str($prompt)->between('4. SİSTEM UYGUNLUĞU', '5. EKİPMAN GRUPLARI'), $provider, $semantic['extracted_data']['equipment_type']['evidence'] ?? null);

            return $this->write($semantic, $systems, $read, $catalogSystems);
        }

        $found = PkLocalRetry::client($provider, fn (array $r) => !collect($r)->contains(fn ($s) => !empty($s['present'])))->extract(
            $this->systemsPrompt($catalogSystems->all(), $readNames, (string) str($prompt)->between('4. SİSTEM UYGUNLUĞU', '5. EKİPMAN GRUPLARI')),
            $structuredText ?? $documentText,
            6000,
            schema: $this->systemsSchema($catalogSystems->pluck('name')->all())
        );

        $systems = [];
        $missing = [];
        // Şemada her katalog sistemi zorunlu bir alan (model listeyi boş bırakıp atlayamıyor); present olanlar alınır.
        foreach ($catalogSystems as $catalogSystem) {
            $item = (array) ($found[$catalogSystem['name']] ?? []);
            if (empty($item['present'])) {
                continue;
            }
            // Ana okumanın bu sisteme ait dediği sistemlerin ekipman tarifleri (model kendi okumasından seçer).
            $from = $read->filter(fn ($system) => in_array($system['system_name'] ?? null, (array) ($item['from_reading'] ?? []), true) || ($system['system_name'] ?? null) === $catalogSystem['name']);
            $definitions = $from->flatMap(fn ($system) => (array) ($system['equipment_definitions'] ?? []))->values()->all();
            $status = in_array($item['status'] ?? null, self::STATUSES, true) ? $item['status'] : null;
            $systems[$catalogSystem['name']] = [
                'system_name' => $catalogSystem['name'],
                'section_heading_patterns' => array_values(array_filter([(string) ($item['heading'] ?? '')])),
                'section_detection' => $from->first()['section_detection'] ?? ['start_heading_patterns' => [], 'continuation_patterns' => [], 'end_detection_patterns' => []],
                'verdict' => ['status' => $status, 'raw' => $item['raw'] ?? null, 'basis' => $status ? ($item['basis'] ?? 'derived') : 'not_stated', 'evidence' => $item['evidence'] ?? null],
                'equipment_definitions' => $definitions,
            ];
        }

        // Docling tablo konumları varsa: tablo haritası (hangi tablo hangi ekipmanın, kimlik ve özellik etiketleri) + yapıyı koddan
        // bulan tarif kurucu; satırları mevcut tablo okuyucu okur. Aşağıdaki eksik ekipman / tablo denetimi adımları çalışmaz.
        if ($doclingTables && $pdfPath) {
            $types = collect($systems)->map(fn ($system, $name) => collect((array) ($catalogSystems->firstWhere('name', $name)['equipment'] ?? []))->pluck('name')->all())->filter()->all();
            // Her sistem ayrı küçük istek (tek istekte bütün sistemler küçük modelde eksik kalıyor: NuExtract3 #18 2/5); girdi
            // yalnız başlıklar ve tablolar. Bir tabloyu ilk alan sistem tutar (satırlar iki kez sayılmasın).
            $tablesText = collect(explode("\n", $structuredText ?? $documentText))->filter(fn ($line) => str_starts_with($line, '#') || str_starts_with($line, '| '))->implode("\n");
            $map = [];
            $taken = [];
            foreach ($types as $name => $equipment) {
                $found = app(PkTableMap::class)->map($tablesText, [$name => $equipment], array_column($doclingTables, 'no'), $tableLabels, $provider)[$name] ?? ['lists' => [], 'singles' => []];
                $found['lists'] = array_values(array_filter($found['lists'], fn ($list) => !array_intersect((array) ($list['tables'] ?? []), $taken)));
                $found['singles'] = array_values(array_filter($found['singles'], fn ($single) => !in_array($single['table'] ?? null, $taken, true)));
                $taken = [...$taken, ...collect($found['lists'])->pluck('tables')->flatten()->all(), ...collect($found['singles'])->pluck('table')->filter()->all()];
                $map[$name] = $found;
            }
            $definitions = app(PkTableDefinitions::class)->build($pdfPath, $doclingTables, $map);
            foreach ($systems as $name => $system) {
                $systems[$name]['equipment_definitions'] = $definitions[$name] ?? [];
            }

            return $this->write($semantic, $systems, $read, $catalogSystems);
        }

        // Tablolar Docling metninden (hangi sistemin altında, özellikleri), etiketler Camelot'tan (satırları Camelot okur).
        // Tablo yönü ayrı küçük istekle denetlenir (küçük model ters tabloyu satır sanıyor, tek tek anlatılan pompaları tablo
        // sanıyor); tablo olmadığı anlaşılan tarif atılır, aşağıda eksik ekipman olarak yeniden sorulur.
        $systems = $this->checkTables($systems, $structuredText ?? $documentText, $prompt, $provider, labels: $tableLabels);

        // Katalogda olup tarif edilmemiş ekipman türleri: hepsi birlikte tek istekte sorulur.
        foreach ($systems as $name => $system) {
            $described = collect($system['equipment_definitions'])->pluck('equipment_name')->all();
            $missing[$name] = collect((array) ($catalogSystems->firstWhere('name', $name)['equipment'] ?? []))->pluck('name')->reject(fn ($equipment) => in_array($equipment, $described, true))->values()->all();
        }
        $missing = array_filter($missing);
        if ($missing) {
            $extra = PkLocalRetry::client($provider, fn (array $r) => !collect($r)->contains(fn ($list) => !empty($list)))->extract(
                $this->equipmentPrompt($missing, collect($systems)->map(fn ($system) => $this->describedTables($system['equipment_definitions']))->all(), (string) str($prompt)->between('5. EKİPMAN GRUPLARI', '6. SONUÇ SEMBOLLERİNİN'), (string) str($prompt)->between('ÖRNEK 2', 'ÖRNEK 3')),
                $structuredText ?? $documentText,
                12000,
                schema: $this->equipmentSchema($missing, $tableLabels)
            );
            foreach (array_keys($missing) as $name) {
                $row = ['equipment_definitions' => (array) ($extra[$name] ?? [])];
                // Daha önce tarif edilmiş tabloyu (aynı kimlik sütunu) ikinci kez eklemez: tablo okuyucu satırları iki kez sayar.
                $known = collect($systems[$name]['equipment_definitions'])->map(fn ($definition) => ($definition['instance_structure']['equipment_axis'] ?? null) === 'none' ? null : ($definition['instance_structure']['identity_field'] ?? null))->filter()->all();
                $new = array_values(array_filter(array_map(fn ($slim) => is_array($slim) ? $this->definition($slim) : null, (array) ($row['equipment_definitions'] ?? [])), fn ($definition) => is_array($definition)
                    && (($definition['instance_structure']['equipment_axis'] ?? null) === 'none' || !in_array($definition['instance_structure']['identity_field'] ?? null, $known, true))));
                $systems[$name]['equipment_definitions'] = [...$systems[$name]['equipment_definitions'], ...$new];
            }
        }
        // Aynı tablo (kimlik etiketi + başlıklar) birden çok sisteme yazıldıysa yalnız ilkinde kalır: tablo okuyucu aksi halde
        // aynı satırları her sistemde yeniden sayar.
        $seen = [];
        foreach ($systems as $name => $system) {
            $systems[$name]['equipment_definitions'] = array_values(array_filter($system['equipment_definitions'], function ($definition) use (&$seen) {
                $structure = (array) ($definition['instance_structure'] ?? []);
                if (($structure['equipment_axis'] ?? null) === 'none') {
                    return true;
                }
                $key = json_encode([$structure['identity_field'] ?? null, $structure['header_patterns'] ?? []], JSON_UNESCAPED_UNICODE);

                return !isset($seen[$key]) && ($seen[$key] = true);
            }));
        }

        // Ekipman adımının yeni tarif ettiği tablolar da aynı denetimden geçer (öncekiler yeniden sorulmaz). Ana okuma yoksa
        // (Docling akışı) tarifler zaten ekipman adımından: ayrı denetim yok.
        if ($missing && $read->isNotEmpty()) {
            $systems = $this->checkTables($systems, $structuredText ?? $documentText, $prompt, $provider, onlyNew: true, labels: $tableLabels);
        }

        return $this->write($semantic, $systems, $read, $catalogSystems);
    }

    // Sistem adımı katalogdan sistem bulamadıysa ana okumanın yalnız katalog adlı sistemleri kalır (ana okuma kusur bölümü
    // gibi başlıkları sistem sanabiliyor).
    private function write(array $semantic, array $systems, $read, $catalogSystems): array
    {
        $systems = $this->unmark($systems);
        $semantic['template']['fire_systems']['systems'] = $systems
            ? array_values($systems)
            : $read->filter(fn ($system) => $catalogSystems->contains('name', $system['system_name'] ?? null))->values()->all();

        return $semantic;
    }

    // Yalnız tablo denetimi (sistem adımı olmadan): ana okumanın tablolarının yönü ve başlıkları, Camelot etiketlerinden.
    public function checkTemplateTables(array $semantic, string $documentText, array $tableLabels, ?string $provider = null): array
    {
        $systems = collect((array) ($semantic['template']['fire_systems']['systems'] ?? []))->filter(fn ($system) => is_array($system))
            ->mapWithKeys(fn ($system, $i) => [$i => ['equipment_definitions' => (array) ($system['equipment_definitions'] ?? [])] + $system])->all();
        if (!$systems) {
            return $semantic;
        }
        $prompt = (string) (new ReflectionMethod(PkReportAnalyzer::class, 'systemPrompt'))->invoke($this->analyzer);
        $semantic['template']['fire_systems']['systems'] = array_values($this->unmark($this->checkTables($systems, $documentText, $prompt, $provider, labels: $tableLabels)));

        return $semantic;
    }

    private function systemsPrompt(array $catalogSystems, array $readNames, string $verdictRules): string
    {
        $list = collect($catalogSystems)->map(fn ($system) => '- ' . $system['name'] . (!empty($system['equipment']) ? ' (ekipman türleri: ' . collect($system['equipment'])->pluck('name')->implode(', ') . ')' : ''))->implode("\n");
        $read = $readNames ? implode(' | ', $readNames) : '(yok)';

        return <<<PROMPT
Sana bir tesisat periyodik kontrol raporunun metni verilecek. Görevin her katalog sisteminin raporda olup olmadığını ve sonucunu yazmak.
- Aşağıdaki HER sistem için bir cevap yaz (hiçbirini atlama). present: sistem raporda var mı? Sistem, raporda KENDİ bölüm başlığı, kendi kontrol listesi bölümü ya da kendi ekipman tablosu / ekipmanı (örn. pompa, depo, dolap, hidrant) olan bölümdür. Adının yalnızca bir kontrol maddesinde ya da açıklamada geçmesi onu sistem yapmaz; öyle sistemi yazma. Bölümü olsa da tesiste bulunmadığı / uygulanamaz olduğu belirtilen (bölüm boş, "-", "Uygulaması Yok", "Mevcut Değil" gibi) sistemi de yazma. Rapordaki bölüm adı farklı olabilir; anlamca karşılık gelen listedeki adı seç. Raporda hiç geçmeyen sistemi yazma.
- Raporu baştan sona oku: bir raporda genelde birden çok sistem vardır (belge kontrolleri, pompa istasyonu, su deposu, dolaplar, hidrantlar, sprinkler, boru tesisatı gibi). Hiçbirini atlama.
- heading: o sistemin rapordaki bölüm başlığı, raporda yazdığı gibi; yoksa null.
- from_reading: ilk okumada bu sisteme ait olarak yazılmış sistem adları şu listeden: {$read}. Bu sisteme ait olanları seç; yoksa [].
- status, raw, basis, evidence: sistemin GENEL uygunluğu; kurallar aşağıda.
SİSTEM LİSTESİ:
{$list}

4. SİSTEM UYGUNLUĞU{$verdictRules}
PROMPT;
    }

    private function describedTables(array $definitions): string
    {
        $tables = collect($definitions)->reject(fn ($definition) => ($definition['instance_structure']['equipment_axis'] ?? null) === 'none')
            ->map(fn ($definition) => '"' . ($definition['equipment_name'] ?? '') . '" (kimlik sütunu "' . ($definition['instance_structure']['identity_field'] ?? '') . '")');

        return $tables->isEmpty() ? '(yok)' : $tables->implode(', ');
    }

    // $missing: [sistem adı => [eksik ekipman türleri]], $described: [sistem adı => tarif edilmiş tablolar].
    private function equipmentPrompt(array $missing, array $described, string $definitionRules, string $columnsExample = ''): string
    {
        $list = collect($missing)->map(fn ($equipment, $system) => '- ' . $system . ': ' . implode(', ', $equipment) . ' (bu sistemde zaten tarif edilmiş tablolar: ' . ($described[$system] ?? '(yok)') . ')')->implode("\n");

        return <<<PROMPT
Sana bir tesisat periyodik kontrol raporunun metni verilecek. Görevin aşağıdaki sistemlerdeki şu ekipman türlerinin equipment_definitions kayıtlarını yazmak:
{$list}
- Cevapta HER sistem adı bir alandır (hiçbirini atlama); değeri o sistemin equipment_definitions listesidir. Her sistemin bölümüne ayrı ayrı bak: ekipman listesi / tablosu, tek tek anlatılan ekipmanlar (pompa, depo gibi).
- Raporda geçmeyen ekipman türünü yazma; sistemde hiçbiri geçmiyorsa equipment_definitions = [].
- equipment_name: yukarıdaki listedeki ad, AYNEN.
- Zaten tarif edilmiş tabloyu başka bir adla yeniden tarif etme; yalnızca bunların dışında kalan ekipmanları yaz. Raporda bir tablo yalnızca BİR sisteme aittir: aynı tabloyu birden çok sisteme yazma; o sisteme ait tablo yoksa equipment_definitions = [].
- Çok sayıda örnek bir tabloda listeleniyorsa (örn. onlarca hidrant) TEK kayıt yaz ve tablonun yapısını tarif et (equipment_axis "rows" ya da "columns"; identity_field ve header_patterns tablonun başlıklarıyla harfi harfine aynı). Gerçek hücreleri tablo okuyucu okur.
- Pompa, depo, vana istasyonu gibi her biri kendi başlığı / bilgi bloğuyla anlatılan ekipman tablo DEĞİLDİR (equipment_axis "none").
- equipment_axis: ekipman numaraları / kodları bir satırın içinde yan yana duruyorsa (örn. "No / Kod YD1 YD2 YD3" ya da "Ekipman 1 2 3 4") → "columns"; bir sütunda alt alta duruyorsa → "rows".
- "columns" ise: group_width ZORUNLU = bir blokta yan yana kaç FARKLI ekipman var (aynı numara birden çok hücrede tekrar ediyorsa bir ekipman sayılır); block_first_label = her bloğun İLK satırının etiketi (kimlik satırından önce gelen satır, örn. "Soru / Kriter"), yoksa null.
- identity_field, block_first_label, attributes[].field: tabloda yazdığı gibi (tablo okuyucu birebir eşleştirir).
- attributes: ekipmanın HER özellik satırı / sütunu: marka, yer ve ölçülen değerler (basınç, uzunluk, mesafe, kapasite gibi) dahil. Değeri uygun / uygun değil gibi bir sonuç olan satır kriterdir; onu yazma.
- Ekipmanın uygun / uygun değil durumunu yazma: durum yalnızca tablodaki kriter sonuçlarından ve bulgulardan gelir (tablo okuyucu ve bulgu adımı bulur).
- Bütün bilgileri boş ("-", boş hücre) olan bölüm / yuva (örn. bilgileri doldurulmamış bir pompa bölümü) gerçek bir ekipman değildir; yazma.
- Ekipman örnekleri raporda tek tek ayrı bölümlerde anlatılıyorsa (örn. 1, 2 ve 3 numaralı pompa ayrı başlıklarda) HER örnek için ayrı kayıt yaz: equipment_axis "none", identity_value ve attributes[].value raporda yazan GERÇEK değerlerle; birini yazıp diğerlerini atlama.
Ayrıntılı kurallar:

5. EKİPMAN GRUPLARI{$definitionRules}

YAN YANA SÜTUNLU TABLO ÖRNEĞİ{$columnsExample}
PROMPT;
    }

    // Her katalog sistemi zorunlu alan: {"<sistem adı>": {present, heading, from_reading, status, raw, basis, evidence}}.
    private function systemsSchema(array $names): array
    {
        $text = ['type' => ['string', 'null']];
        $item = ['type' => 'object', 'properties' => [
            'present' => ['type' => 'boolean'],
            'heading' => $text,
            'from_reading' => ['type' => 'array', 'items' => ['type' => 'string']],
            'status' => ['type' => ['string', 'null'], 'enum' => [...self::STATUSES, null]],
            'raw' => $text,
            'basis' => ['type' => 'string', 'enum' => ['explicit', 'derived', 'not_stated']],
            'evidence' => $text,
        ], 'required' => ['present', 'heading', 'from_reading', 'status', 'raw', 'basis', 'evidence']];

        return ['type' => 'object', 'properties' => array_fill_keys($names, $item), 'required' => $names];
    }

    // Sözleşmedeki equipment_definitions'ın yalnız modelin bilmesi gereken alanları (çıktı ~4 kat kısa); sözleşme kaydını
    // definition() kurar.
    // $labels: Camelot'un etiketleri; varsa başlıklar o listeden seçilir.
    // $missing: [sistem adı => [ekipman türleri]]; her sistem zorunlu alan (model sistem atlayamaz).
    private function equipmentSchema(array $missing, array $labels = []): array
    {
        $text = ['type' => ['string', 'null']];
        $label = $labels ? ['type' => 'string', 'enum' => $labels] : ['type' => 'string'];
        $definition = ['type' => 'object', 'properties' => [
            'equipment_name' => ['type' => 'string', 'enum' => array_values(array_unique(array_merge(...array_values($missing))))],
            'equipment_axis' => ['type' => 'string', 'enum' => ['rows', 'columns', 'none']],
            'identity_field' => $labels ? ['type' => ['string', 'null'], 'enum' => [...$labels, null]] : $text,
            'identity_value' => $text,
            'group_width' => ['type' => ['integer', 'null']],
            'block_first_label' => $labels ? ['type' => ['string', 'null'], 'enum' => [...$labels, null]] : $text,
            'attributes' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['field' => $label, 'value' => $text], 'required' => ['field', 'value']]],
        ], 'required' => ['equipment_name', 'equipment_axis', 'identity_field', 'identity_value', 'group_width', 'block_first_label', 'attributes']];
        $names = array_keys($missing);

        return ['type' => 'object', 'properties' => array_fill_keys($names, ['type' => 'array', 'items' => $definition]), 'required' => $names];
    }

    // Sade cevaptan JSON sözleşmesindeki equipment_definitions kaydı.
    private function definition(array $slim): array
    {
        $axis = $slim['equipment_axis'] ?? 'none';
        $identity = $slim['identity_field'] ?? null;
        $fields = array_values(array_filter(array_column(array_filter((array) ($slim['attributes'] ?? []), 'is_array'), 'field')));
        // Tablo başlığı: sütunlu tabloda bloğun ilk satırı + kimlik (ilk satır başlıkta yoksa okuyucu onu kriter sanıyor);
        // satırlı tabloda kimlik + özellik sütunları.
        $headers = match ($axis) {
            'columns' => array_values(array_unique(array_filter([$slim['block_first_label'] ?? null, $identity]))),
            'rows' => array_values(array_unique(array_filter([$identity, ...$fields]))),
            default => [],
        };

        return [
            'equipment_name' => (string) ($slim['equipment_name'] ?? ''),
            'instance_structure' => [
                'identity_field' => $slim['identity_field'] ?? null,
                'identity_value' => $axis === 'none' ? ($slim['identity_value'] ?? null) : null,
                'identity_hint' => ['pattern' => null, 'validation' => 'soft'],
                'equipment_axis' => $axis,
                'group_width' => $axis === 'columns' ? ($slim['group_width'] ?? null) : null,
                'header_patterns' => $headers,
                'result_columns' => [],
                'ambiguous' => false,
                'ambiguous_reason' => null,
            ],
            'attributes' => array_map(fn ($pair) => ['field' => $pair['field'] ?? '', 'source_pattern' => $pair['field'] ?? '', 'value' => $axis === 'none' ? ($pair['value'] ?? null) : null], array_values(array_filter((array) ($slim['attributes'] ?? []), 'is_array'))),
            // Durum ekipman adımından gelmez: kriter sonucu (tablo okuyucu) ya da bulgu yoksa bilinmiyor.
            'verdict' => ['status' => null, 'raw' => null, 'basis' => 'not_stated', 'evidence' => null],
        ];
    }

    // Bulgu eşleştirmesi için: sistem adı => katalogdaki ekipman türleri.
    public static function equipmentBySystem(array $catalog, ?string $typeSlug): array
    {
        $type = collect($catalog)->firstWhere('slug', $typeSlug);

        return collect((array) ($type['systems'] ?? []))->filter(fn ($system) => is_array($system))
            ->mapWithKeys(fn ($system) => [$system['name'] => collect((array) ($system['equipment'] ?? []))->pluck('name')->all()])->all();
    }

    // $onlyNew: yalnız henüz denetlenmemiş tablolar (denetlenenler _checked ile işaretlenir, çıktıdan önce silinir).
    private function checkTables(array $systems, string $documentText, string $prompt, ?string $provider, bool $onlyNew = false, array $labels = []): array
    {
        $tables = [];
        foreach ($systems as $name => $system) {
            foreach ($system['equipment_definitions'] as $d => $definition) {
                if (in_array($definition['instance_structure']['equipment_axis'] ?? null, ['rows', 'columns'], true) && !($onlyNew && !empty($definition['_checked']))) {
                    $tables[] = [$name, $d, $definition];
                }
            }
        }
        if (!$tables) {
            return $this->unmark($systems);
        }
        $list = collect($tables)->map(fn ($table, $i) => $i . ': "' . ($table[2]['equipment_name'] ?? '') . '" (sistem: ' . ($systems[$table[0]]['system_name'] ?? $table[0]) . ', kimlik etiketi: "' . ($table[2]['instance_structure']['identity_field'] ?? '') . '")')->implode("\n");
        $rules = (string) str($prompt)->between('- equipment_axis: tabloda', '- result_columns');
        $example = (string) str($prompt)->between('ÖRNEK 2', 'ÖRNEK 3');
        $schema = ['type' => 'object', 'properties' => ['tables' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'index' => ['type' => 'integer'],
            'equipment_axis' => ['type' => 'string', 'enum' => ['rows', 'columns', 'none']],
            'group_width' => ['type' => ['integer', 'null']],
            // Camelot'un etiketleri varsa başlıklar o listeden seçilir: tablo okuyucu (Camelot) birebir eşleştirir.
            'identity_field' => $labels ? ['type' => 'string', 'enum' => $labels] : ['type' => 'string'],
            'header_patterns' => ['type' => 'array', 'items' => $labels ? ['type' => 'string', 'enum' => $labels] : ['type' => 'string']],
            'attributes' => ['type' => 'array', 'items' => $labels ? ['type' => 'string', 'enum' => $labels] : ['type' => 'string']],
        ], 'required' => ['index', 'equipment_axis', 'group_width', 'identity_field', 'header_patterns', 'attributes']]]], 'required' => ['tables']];

        $found = PkLocalRetry::client($provider, fn (array $r) => empty($r['tables']))->extract(<<<PROMPT
Sana bir tesisat periyodik kontrol raporunun metni verilecek. Raporda aşağıdaki ekipman tabloları var. Her tablo için tablonun YÖNÜNÜ ve başlıklarını raporda gördüğün gibi yaz.
{$list}
- index: yukarıdaki numara.
- equipment_axis: tabloda her ekipman bir SATIR mı ("rows"), yoksa ekipmanlar yan yana birer SÜTUN mu ("columns")? Bu aslında bir tablo değil de ekipmanlar (örn. pompalar) her biri kendi başlığı / bilgi bloğuyla tek tek anlatılıyorsa "none". Kesin yol: ekipman numaraları / kodları bir satırın içinde yan yana duruyorsa (örn. "No / Kod YD1 YD2 YD3" ya da "Ekipman 1 2 3 4") → "columns"; bir sütunda alt alta duruyorsa → "rows".
- group_width: "columns" ise ZORUNLU: bir blokta yan yana kaç ekipman var (örn. blok "No / Kod YD1 YD2 YD3 YD4" ise 4); "rows" ise null.
- attributes: ekipmanın özellik satırlarının ("columns") ya da sütunlarının ("rows") etiketleri, raporda yazdığı gibi (örn. "Marka", "Bulunduğu Yer", "Ölçülen Basınç"); kriter / soru maddelerini yazma.
- identity_field: ekipman numarasının / kodunun yazdığı satırın ya da sütunun etiketi, raporda yazdığı gibi.
- header_patterns: yeni bir tablo / blok başladığını gösteren etiketler, raporda yazdığı gibi. "columns" tablolarında her bloğun İLK satırının etiketi (ekipman numaralarının solundaki etiket) ve kimlik etiketi ayrıysa İKİSİNİ de yaz.
Kurallar (mevcut talimattan):
- equipment_axis: tabloda{$rules}
ÖRNEK{$example}
PROMPT, $documentText, 4000, schema: $schema);

        foreach ((array) ($found['tables'] ?? []) as $row) {
            [$name, $d] = $tables[(int) ($row['index'] ?? -1)] ?? [null, null];
            if ($name !== null && ($row['equipment_axis'] ?? null) === 'none') {
                $systems[$name]['equipment_definitions'][$d] = null; // tablo değil: eksik ekipman olarak yeniden sorulur
                continue;
            }
            if ($name === null || !in_array($row['equipment_axis'] ?? null, ['rows', 'columns'], true) || blank($row['identity_field'] ?? null)) {
                continue;
            }
            $structure = &$systems[$name]['equipment_definitions'][$d]['instance_structure'];
            $structure['equipment_axis'] = $row['equipment_axis'];
            $structure['group_width'] = $row['equipment_axis'] === 'columns' ? ($row['group_width'] ?? null) : null;
            $structure['identity_field'] = trim((string) $row['identity_field']);
            $structure['header_patterns'] = array_values(array_filter(array_map(fn ($header) => trim((string) $header), (array) ($row['header_patterns'] ?? []))));
            unset($structure);
            $labels = array_values(array_filter(array_map(fn ($label) => trim((string) $label), (array) ($row['attributes'] ?? []))));
            if ($labels) {
                $systems[$name]['equipment_definitions'][$d]['attributes'] = array_map(fn ($label) => ['field' => $label, 'source_pattern' => $label, 'value' => null], $labels);
            }
        }

        foreach ($tables as [$name, $d]) {
            if (isset($systems[$name]['equipment_definitions'][$d])) {
                $systems[$name]['equipment_definitions'][$d]['_checked'] = true;
            }
        }
        foreach ($systems as $name => $system) {
            $systems[$name]['equipment_definitions'] = array_values(array_filter($system['equipment_definitions']));
        }

        return $onlyNew ? $this->unmark($systems) : $systems;
    }

    private function unmark(array $systems): array
    {
        foreach ($systems as $name => $system) {
            $systems[$name]['equipment_definitions'] = array_map(function ($definition) {
                unset($definition['_checked']);

                return $definition;
            }, $system['equipment_definitions']);
        }

        return $systems;
    }
}
