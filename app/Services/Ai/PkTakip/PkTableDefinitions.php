<?php

namespace App\Services\Ai\PkTakip;

/**
 * Tablo tarifi kurucu (yerel model, tesisat, Docling akışı): tablo haritasının (PkTableMap) cevabından JSON sözleşmesindeki
 * equipment_definitions'ı kurar; satırları mevcut tablo okuyucu (PkReportTableReader) okur. Yapı modele sorulmaz, Docling'in
 * gösterdiği alandaki Camelot tablosundan bulunur: kimlik etiketiyle aynı satırda özellik etiketi varsa "rows"; yoksa
 * "columns" (blok genişliği = kimlik satırındaki ekipman sayısı, blok başlığı = kimlik satırının üstündeki satır). Tek
 * ekipmanlı bilgi tablosu (pompa gibi) "none": değerleri o tablodan.
 */
class PkTableDefinitions
{
    public function __construct(private PkCachedCamelot $camelot)
    {
    }

    /**
     * @param list<array{no: int, page: int, area: string}> $doclingTables pk_docling.py "tables"
     * @param array<string, array{lists: list<array>, singles: list<array>}> $map PkTableMap::map()
     * @return array<string, list<array>> sistem adı => equipment_definitions
     */
    public function build(string $pdfPath, array $doclingTables, array $map): array
    {
        $camelot = array_values(array_filter((array) ($this->camelot->extract($pdfPath)['tables'] ?? []), fn ($table) => is_array($table['data'] ?? null)));
        $areas = collect($doclingTables)->keyBy('no');
        $out = [];
        foreach ($map as $system => $found) {
            // Ajan bir listeyi hem satır satır tek ekipman hem de tipe göre birkaç liste diye verebiliyor (#141 debi tablosu: 14
            // tek + "ASPİRATÖR" / "VANTİLATÖR" listeleri, 22 kayıt). Aynı tablolar + aynı kimlik etiketli ikinci liste kopyadır;
            // tarifi kurulan listenin tablolarındaki tek ekipmanlar listenin satırlarıdır.
            $seen = [];
            $covered = [];
            foreach ((array) ($found['lists'] ?? []) as $list) {
                $identity = (string) ($list['identity_label'] ?? '');
                $labels = array_values((array) ($list['property_labels'] ?? []));
                $key = json_encode([collect((array) ($list['tables'] ?? []))->map(fn ($no) => (int) $no)->sort()->values(), $identity]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                foreach ((array) ($list['tables'] ?? []) as $no) {
                    $area = $areas[$no] ?? null;
                    $grid = $area ? $this->grid($camelot, (int) $area['page'], (string) $area['area']) : [];
                    $definition = $this->definition($grid, (string) $list['equipment_name'], $identity, $labels);
                    // Sayfa kayması: haritada bölünmüş listenin yalnız başlıksız devamı varsa başlık geride, aynı sütun sayılı tablodadır
                    // (#122: başlık 2. sayfada, satırların devamı 3. sayfada; başlıklı tabloyu bölüm etiketleyici rapor bilgisine saymıştı).
                    // Kimlik etiketi tabloda varsa (boş pompa yuvası gibi) devam tablosu değildir; geride tek ekipmanlı bilgi tablosu
                    // çıkarsa da değildir (ajanın yanlış kimlik etiketi #18 pano listesini önceki pompa tablosuna kaydırıyordu).
                    $continuation = $definition === null && $grid && !$this->labelPositions($grid, [$identity]);
                    $source = $grid;
                    for ($prev = (int) $no - 1; $continuation && $definition === null && $prev > 0; $prev--) {
                        $before = isset($areas[$prev]) ? $this->grid($camelot, (int) $areas[$prev]['page'], (string) $areas[$prev]['area']) : [];
                        if (count($before[0] ?? []) !== count($grid[0])) {
                            break;
                        }
                        $definition = $this->definition($before, (string) $list['equipment_name'], $identity, $labels);
                        $source = $before;
                        if ($definition && $definition['instance_structure']['equipment_axis'] === 'none') {
                            $definition = null;
                            break;
                        }
                    }
                    if ($definition === null) {
                        continue;
                    }
                    // Alt sütunlu liste (PK.239 pompa: her pompa "P: - | U: ELEKTRİK" iki sütun; okuyucu yalnız ilkini, proje değerini
                    // alıyordu): her ekipman tek ekipman tarifi olur, değerini tablonun lejantıyla değer ajanı seçer.
                    $groups = $definition['instance_structure']['equipment_axis'] === 'columns' ? $this->subColumns($source, $identity) : null;
                    if ($groups) {
                        $out[$system] = [...($out[$system] ?? []), ...$this->groupDefinitions((string) $list['equipment_name'], $identity, $groups)];
                        $covered = [...$covered, ...array_map('intval', (array) $list['tables'])];
                        break;
                    }
                    $out[$system][] = $definition;
                    // Liste tarifi bir kez yeter: tablo okuyucu devam sayfalarını başlıktan bulur. Tek ekipmanlılar her tablo için.
                    if ($definition['instance_structure']['equipment_axis'] !== 'none') {
                        $covered = [...$covered, ...array_map('intval', (array) $list['tables'])];
                        break;
                    }
                }
            }
            foreach ((array) ($found['singles'] ?? []) as $single) {
                if (in_array((int) ($single['table'] ?? 0), $covered, true)) {
                    continue;
                }
                $values = collect((array) ($single['properties'] ?? []))->filter(fn ($pair) => is_array($pair) && filled($pair['label'] ?? null))->mapWithKeys(fn ($pair) => [$pair['label'] => $pair['value'] ?? null])->all();
                // Değer Camelot hücresinden (etiketin sağındaki hücre); etiket tabloda yoksa ajanın yazdığı. Ajan boş pompa yuvasında
                // komşu hücrenin birimini ("m³/h -") değer yazıyordu, yuva boş sayılmıyordu (#18 3 numaralı pompa).
                $area = $areas[$single['table'] ?? null] ?? null;
                $grid = $area ? $this->grid($camelot, (int) $area['page'], (string) $area['area']) : [];
                foreach ($this->labelPositions($grid, array_keys($values)) as $label => [$r, $c]) {
                    $values[$label] = ($grid[$r][$c + 1] ?? '') !== '' ? $grid[$r][$c + 1] : null;
                }
                if (!$this->blank($values)) {
                    $out[$system][] = $this->contract((string) $single['equipment_name'], 'none', null, $single['identity_value'] ?? null, null, [], $values);
                }
            }
        }

        return $out;
    }

    /**
     * Docling tablosunun (numarası) Camelot satırları, hücreler " | " ile: kontrol listesi ajanının girdisi (bütün satırlar; Docling
     * metninde uzun tablonun yalnız başı var).
     *
     * $groups: kontrol listesi ajanının bulduğu grup başlıkları ve kodları. Verilirse sonuç sütunla işaretlenen formda hücreler sütun
     * başlığıyla etiketlenir ("U.D.: ✓"): küçük model ✓ işaretinin hangi sütunda olduğunu sayamıyordu (PK25 madde 29).
     *
     * @param list<int> $tableNos
     * @param list<string> $groups
     */
    public function rows(string $pdfPath, array $doclingTables, array $tableNos, array $groups = []): string
    {
        $camelot = array_values(array_filter((array) ($this->camelot->extract($pdfPath)['tables'] ?? []), fn ($table) => is_array($table['data'] ?? null)));
        $areas = collect($doclingTables)->keyBy('no');
        $out = [];
        foreach ($tableNos as $no) {
            $area = $areas[$no] ?? null;
            $grid = $area ? $this->grid($camelot, (int) $area['page'], (string) $area['area']) : [];
            foreach ($groups ? $this->labelled($grid, $groups) : $grid as $row) {
                if (array_filter($row, fn ($cell) => $cell !== '')) {
                    $out[] = '| ' . implode(' | ', $row) . ' |';
                }
            }
        }

        return implode("\n", $out);
    }

    // Başlık satırı: bir grup başlığı içeren ilk satır (örn. "TESTLER VE KONTROLLER | U. | U.D. | N.U. | … | Sistemin Genel Kontrolleri
    // | U. | U.D. | N.U."). Altındaki satırlarda dolu hücre, sütununun başlığıyla yazılır; grup başlığı / kodu ya da boş başlıklı
    // sütunlar (madde no, madde metni) olduğu gibi kalır. Yan yana gruplu formda her sütun bölgesinin (başlık satırındaki grup
    // başlığından başlar) son grup başlığı maddenin önüne yazılır ("[Su Deposu Kontrolleri] 8 …"): küçük model kodsuz maddenin
    // grubunu bulamıyordu (PK25). Grup başlık satırı bölgesi olduğu gibi kalır. Başlığın altında her bölge ayrı satırdır: iki madde
    // tek satırdayken küçük model sağdakini atlıyordu (PK25: "9 İstasyon yeri ✓ | 29 Hidrostatik test U.D." → 29 kaçtı).
    private function labelled(array $grid, array $groups): array
    {
        $isGroup = fn (array $cells) => (bool) array_intersect($cells, $groups);
        $header = collect($grid)->search(fn (array $row) => $isGroup($row));
        if ($header === false) {
            return $grid;
        }
        $labels = array_map(fn ($cell) => in_array($cell, $groups, true) ? '' : $cell, $grid[$header]);
        // Bölgeler: başlık satırındaki her grup başlığı sütunu, solundaki boş başlıklı sütunlarla (madde no) birlikte.
        // Yan yana grup hücreleri (kod + başlık: "A | Belge ve Kayıt Kontrolleri", #18) tek bölgedir.
        $titles = array_keys(array_filter($grid[$header], fn ($cell) => in_array($cell, $groups, true)));
        $titles = array_values(array_filter($titles, fn ($t) => !in_array($t - 1, $titles, true)));
        $starts = [];
        foreach ($titles as $k => $t) {
            $bound = $k > 0 ? $titles[$k - 1] + 1 : 0;
            for ($s = $t; $s > $bound && ($labels[$s - 1] ?? '') === ''; $s--);
            $starts[$s] = null;
        }
        $bounds = array_keys($starts);
        $longest = fn (array $cells) => collect($cells)->filter(fn ($cell) => in_array($cell, $groups, true))->sortByDesc(fn ($cell) => mb_strlen($cell))->first();
        foreach ($bounds as $k => $start) {
            $starts[$start] = $longest(array_slice($grid[$header], $start, ($bounds[$k + 1] ?? count($grid[$header])) - $start));
        }

        $out = [];
        foreach ($grid as $r => $row) {
            if ($r <= $header) {
                $out[] = $row;
                continue;
            }
            foreach ($bounds as $k => $start) {
                // İlk bölge satırın başından (ilk grup başlığının solundaki sütunlar düşmesin).
                $from = $k === 0 ? 0 : $start;
                $cells = array_slice($row, $from, ($bounds[$k + 1] ?? count($row)) - $from, true);
                if ($isGroup($cells)) {
                    // Bu bölgenin yeni grubu: başlık hücresi (kod değil, en uzun grup hücresi).
                    $starts[$start] = $longest($cells);
                    $out[] = array_values($cells);
                    continue;
                }
                $first = true;
                foreach ($cells as $c => $cell) {
                    if ($cell === '') {
                        continue;
                    }
                    $cells[$c] = ($first ? '[' . $starts[$start] . '] ' : '') . (($labels[$c] ?? '') !== '' ? $labels[$c] . ': ' . $cell : $cell);
                    $first = false;
                }
                $out[] = array_values($cells);
            }
        }

        return $out;
    }

    private function definition(array $grid, string $name, string $identity, array $labels): ?array
    {
        $hits = [];
        foreach ($grid as $r => $row) {
            foreach ($row as $c => $cell) {
                if ($identity !== '' && $cell === $identity) {
                    $hits[] = [$r, $c];
                }
            }
        }
        if (!$hits) {
            return null;
        }
        [$r, $c] = $hits[0];
        if (array_intersect($labels, $grid[$r])) {
            return $this->contract($name, 'rows', $identity, null, null, array_values(array_unique([$identity, ...array_intersect($labels, $grid[$r])])), array_fill_keys($labels, null));
        }

        $spans = $this->spans($grid[$r], $c);
        // Tek blok, tek ekipman: bilgi tablosu (örn. bir pompa); değerler etiketin hizasından (etiket + değer çiftleri).
        if (count($hits) === 1 && count($spans) === 1) {
            [$start, $width, $code] = $spans[0];
            $positions = $this->labelPositions($grid, $labels);
            $values = [];
            foreach ($labels as $label) {
                [$lr, $lc] = $positions[$label] ?? [null, null];
                $values[$label] = $lr === null ? null : $this->join(array_slice($grid[$lr], $start + $lc - $c, $width));
            }

            return $this->blank($values) ? null : $this->contract($name, 'none', $identity, $code, null, [], $values);
        }

        // Blok başlığı: kimlik satırının hemen üstündeki satır, aynı sütunlarda değer taşıyorsa (örn. "Soru / Kriter | 1 | 2").
        $above = $grid[$r - 1] ?? [];
        $first = ($above[$c] ?? '') !== '' && count($this->spans($above, $c)) === count($spans) ? $above[$c] : null;

        return $this->contract($name, 'columns', $identity, null, count($spans), array_values(array_filter([$first, $identity])), array_fill_keys($labels, null));
    }

    // Docling alanıyla en çok örtüşen Camelot tablosu (aynı sayfa; çizgili okuma önce). Alan: "x1,y1,x2,y2" sol-üst → sağ-alt.
    private function grid(array $camelot, int $page, string $area): array
    {
        [$l, $t, $r, $b] = array_map('floatval', explode(',', $area));
        $best = null;
        $bestScore = 0.5; // kesişim birleşimin en az yarısı
        foreach ($camelot as $table) {
            if ((int) ($table['page'] ?? 0) !== $page || count((array) ($table['bbox'] ?? [])) !== 4) {
                continue;
            }
            [$x1, $y1, $x2, $y2] = array_map('floatval', $table['bbox']);
            $overlap = max(0, min($r, max($x1, $x2)) - max($l, min($x1, $x2))) * max(0, min($t, max($y1, $y2)) - max($b, min($y1, $y2)));
            // Kesişim / birleşim: alanı içine alan büyük tablo (çizgisiz okumada bütün sayfa) yüksek puan almasın.
            $union = (($r - $l) * ($t - $b) + abs($x2 - $x1) * abs($y2 - $y1) - $overlap) ?: 1;
            $score = $overlap / $union + (($table['flavor'] ?? '') === 'lattice' ? 0.05 : 0);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$table, $score];
            }
        }

        return $best ? array_map(fn ($row) => array_map(fn ($cell) => trim((string) $cell), array_values((array) $row)), $best['data']) : [];
    }

    /**
     * Kimlik satırında ekipmanlar arasında boş sütun var ve başka satırlar o sütunları dolduruyorsa ekipmanın alt sütunları vardır
     * (proje / uygulama değeri gibi): ['equipment' => [kimlik => [etiket => grubun hücreleri (alt sütun sırasıyla)]], 'notes' =>
     * yalnız etiket hücresi dolu satırlar (lejant, başlık)]; yoksa null.
     * shortcut: yalnız tek bloklu tablo (kimlik etiketi bir kez); bloklu alt sütunlu form çıkarsa bloklara bölünmeli.
     */
    private function subColumns(array $grid, string $identity): ?array
    {
        if (collect($grid)->flatten()->filter(fn ($cell) => $cell === $identity)->count() !== 1) {
            return null;
        }
        [$r, $c] = $this->labelPositions($grid, [$identity])[$identity];
        $spans = $this->spans($grid[$r], $c);
        $equipment = [];
        $notes = [];
        $split = false;
        foreach ($grid as $i => $row) {
            $label = $row[$c] ?? '';
            if ($i === $r || $label === '') {
                continue;
            }
            if (!array_filter(array_slice($row, $c + 1), fn ($cell) => $cell !== '')) {
                $notes[] = $label;
                continue;
            }
            foreach ($spans as $k => [$start, $width, $id]) {
                $cells = array_slice($row, $start, ($spans[$k + 1][0] ?? count($row)) - $start);
                $split = $split || (bool) array_filter(array_slice($cells, $width), fn ($cell) => $cell !== '');
                $equipment[$id][$label] = array_values($cells);
            }
        }

        return $split ? ['equipment' => $equipment, 'notes' => $notes] : null;
    }

    // Alt sütunlu listenin ekipmanları. Ajan yalnız lejantın işaretlerini anlamlandırır (P: proje, U: uygulama); ekipmanın kendi
    // (uygulamadaki) değerinin alt sütunu, hücreleri o işaretle başlayan sütundur, hücreleri kod seçer. Küçük model hücre hücre
    // seçerken sayıları boş bıraktı, sütun numarası sorulunca lejanta rağmen proje sütununu seçti (PK.239). İşaret bulunamazsa
    // eski okuma gibi ilk alt sütun. Ekipman her durumda listelenir (eski okumada da her kimlik bir ekipmandı).
    private function groupDefinitions(string $name, string $identity, array $groups): array
    {
        $marker = $groups['notes'] ? $this->actualValueMarker($groups['notes']) : '';
        $cells = array_merge(...array_map('array_values', array_values($groups['equipment'])));
        $hits = [];
        foreach ($cells as $row) {
            foreach ($row as $i => $cell) {
                $hits[$i] = ($hits[$i] ?? 0) + ($marker !== '' && str_starts_with($cell, $marker) ? 1 : 0);
            }
        }
        $position = $hits && max($hits) > 0 ? array_search(max($hits), $hits, true) : 0;
        $marker = $position === 0 && !($hits[0] ?? 0) ? '' : $marker;

        return collect($groups['equipment'])->map(function (array $values, $id) use ($name, $identity, $position, $marker) {
            $chosen = collect($values)->map(fn (array $cells, $label) => $this->subValue($cells, $position, $marker, (string) $label))->all();

            return $this->contract($name, 'none', $identity, (string) $id, null, [], $chosen);
        })->values()->all();
    }

    // Lejanttaki "uygulamadaki / tespit edilen değer" işareti (hücrede yazdığı gibi, örn. "U:"); yoksa ''.
    private function actualValueMarker(array $notes): string
    {
        $found = PkLocalRetry::client(null, fn () => false)->extract(
            "Sana bir ekipman tablosunun işaret / lejant açıklaması verilecek. Açıklamadaki HER işaret için anlamını yaz.\n- marker = işaret, açıklamada yazdığı gibi (örn. \"P:\").\n- meaning = \"proje\" (projedeki / tasarım değeri), \"uygulama\" (uygulamadaki / mevcut / tespit edilen ekipmanın değeri) ya da \"diger\".",
            implode("\n", $notes),
            300,
            schema: ['type' => 'object', 'properties' => ['markers' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'object', 'properties' => [
                'marker' => ['type' => 'string'],
                'meaning' => ['type' => 'string', 'enum' => ['proje', 'uygulama', 'diger']],
            ], 'required' => ['marker', 'meaning']]]], 'required' => ['markers']]
        );

        return trim((string) (collect((array) ($found['markers'] ?? []))->firstWhere('meaning', 'uygulama')['marker'] ?? ''));
    }

    // Grubun seçilen alt sütunundaki değer, işareti ("U:") atılmış; o hücre boşsa birleşik hücre (marka, seri no iki alt sütuna
    // yayılır): grubun tek dolu hücresi. Boş değer ("-") null.
    private function subValue(array $cells, ?int $position, string $marker, string $label): ?string
    {
        $filled = array_values(array_filter($cells, fn ($cell) => $cell !== ''));
        $value = $position === null ? null : (($cells[$position] ?? '') !== '' ? $cells[$position] : (count($filled) === 1 ? $filled[0] : null));
        if ($value !== null && $marker !== '' && str_starts_with($value, $marker)) {
            $value = trim(substr($value, strlen($marker)), " :");
        }

        return $value === null || $this->blank([$label => $value]) ? null : $value;
    }

    // Etiketlerin tablodaki yeri [satır, sütun] (ilk görülen).
    private function labelPositions(array $grid, array $labels): array
    {
        $positions = [];
        foreach ($grid as $r => $row) {
            foreach ($row as $c => $cell) {
                if (in_array($cell, $labels, true) && !isset($positions[$cell])) {
                    $positions[$cell] = [$r, $c];
                }
            }
        }

        return $positions;
    }

    // Satırda etiketin sağındaki ekipmanlar: [başlangıç sütunu, genişlik, kimlik]; birleşik hücrede yan yana tekrar eden aynı
    // değer tek ekipmandır (genişliği tekrar sayısı). Boş hücre ekipman değildir.
    private function spans(array $row, int $c): array
    {
        $spans = [];
        for ($j = $c + 1; $j < count($row); $j++) {
            $last = count($spans) - 1;
            if ($row[$j] === '') {
                continue;
            }
            if ($last >= 0 && $row[$j] === $spans[$last][2] && $spans[$last][0] + $spans[$last][1] === $j) {
                $spans[$last][1]++;
                continue;
            }
            $spans[] = [$j, 1, $row[$j]];
        }

        return $spans;
    }

    private function join(array $cells): ?string
    {
        $value = trim(implode(' ', array_filter($cells, fn ($cell) => $cell !== '')));

        return $value === '' ? null : $value;
    }

    // Bütün değerleri boş olan yuva (örn. doldurulmamış pompa bölümü) ekipman değildir. Değerde etiketin kendi sözcükleri
    // (birim: "Debi (m3/h)" → "- m3/h") dışında harf / rakam yoksa değer boştur. Komşu hücreden taşan birim de ("Pompa Seri No" →
    // "- m³/h", #18) değer değildir: kalan sözcükler rakamsız ve kısaysa (m³, h, kW) boş.
    private function blank(array $values): bool
    {
        $words = fn ($text) => array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $text, 'UTF-8')));
        foreach ($values as $label => $value) {
            foreach (array_diff($words($value), $words($label)) as $word) {
                if (mb_strlen($word) >= 3 || preg_match('/\d/', $word)) {
                    return false;
                }
            }
        }

        return true;
    }

    // JSON sözleşmesindeki equipment_definitions kaydı; durum burada yok (kriter sonucu tablo okuyucudan, yoksa bilinmiyor).
    private function contract(string $name, string $axis, ?string $identity, ?string $identityValue, ?int $groupWidth, array $headers, array $values): array
    {
        return [
            'equipment_name' => $name,
            'instance_structure' => [
                'identity_field' => $identity,
                'identity_value' => $identityValue,
                'identity_hint' => ['pattern' => null, 'validation' => 'soft'],
                'equipment_axis' => $axis,
                'group_width' => $groupWidth,
                'header_patterns' => $headers,
                'result_columns' => [],
                'ambiguous' => false,
                'ambiguous_reason' => null,
            ],
            'attributes' => array_map(fn ($label, $value) => ['field' => $label, 'source_pattern' => $label, 'value' => $value], array_keys($values), array_values($values)),
            'verdict' => ['status' => null, 'raw' => null, 'basis' => 'not_stated', 'evidence' => null],
        ];
    }
}
