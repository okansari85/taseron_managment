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
            foreach ((array) ($found['lists'] ?? []) as $list) {
                $identity = (string) ($list['identity_label'] ?? '');
                $labels = array_values((array) ($list['property_labels'] ?? []));
                foreach ((array) ($list['tables'] ?? []) as $no) {
                    $area = $areas[$no] ?? null;
                    $grid = $area ? $this->grid($camelot, (int) $area['page'], (string) $area['area']) : [];
                    $definition = $this->definition($grid, (string) $list['equipment_name'], $identity, $labels);
                    if ($definition === null) {
                        continue;
                    }
                    $out[$system][] = $definition;
                    // Liste tarifi bir kez yeter: tablo okuyucu devam sayfalarını başlıktan bulur. Tek ekipmanlılar her tablo için.
                    if ($definition['instance_structure']['equipment_axis'] !== 'none') {
                        break;
                    }
                }
            }
            foreach ((array) ($found['singles'] ?? []) as $single) {
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
    // grubunu bulamıyordu (PK25). Grup başlık satırı bölgesi olduğu gibi kalır.
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
                $end = $bounds[$k + 1] ?? count($row);
                $cells = array_slice($row, $start, $end - $start, true);
                if ($isGroup($cells)) {
                    // Bu bölgenin yeni grubu: başlık hücresi (kod değil, en uzun grup hücresi).
                    $starts[$start] = $longest($cells);
                    continue;
                }
                $first = true;
                foreach ($cells as $c => $cell) {
                    if ($cell === '') {
                        continue;
                    }
                    $row[$c] = ($first ? '[' . $starts[$start] . '] ' : '') . (($labels[$c] ?? '') !== '' ? $labels[$c] . ': ' . $cell : $cell);
                    $first = false;
                }
            }
            $out[] = $row;
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
