<?php
namespace App\Services\Ai;

/**
 * Universal, firma/şablon bağımsız tablo analizörü.
 * Gemini semantic veri üretir; ekipman ve kontrol verisi tablo yapısından çıkarılır.
 *
 * V7: Kontrol tablolarında aynı satır içinde birden fazla kontrol kodunu
 * ayırır ve her kodun kendi açıklama/status grubunu üretir.
 *
 * Kontrol normalizasyonu:
 * - Bir sayısal değer tek başına kontrol kodu kabul edilmez; kodun yanında U/UD/N
 *   sonucu bulunmalıdır.
 * - Aynı kontrol kodu ve açıklama birden fazla sayfada tekrarlanırsa tek kayıtta
 *   birleştirilir ve sonuçlar birleştirilir.
 */
class UniversalFireSuppressionTableAnalyzerV7
{
    private const IDENTITY_LABELS = ['no kod','kod','no','ekipman no','cihaz no','etiket','dolap no','pompa no','hidrant no'];
    private const LOCATION_LABELS = ['kat','bulundugu yer','lokasyon','konum','yer'];
    private const BRAND_LABELS = ['marka','uretici'];
    private const MODEL_LABELS = ['model','model no'];
    private const SERIAL_LABELS = ['seri no','serino','seri numarasi'];

    public function analyze(array $pages, array $semantic): array
    {
        $tables = $this->discover($pages);
        $systems = [];
        $equipment = [];
        $controls = [];
        $used = [];

        foreach (array_values(array_filter((array)($semantic['systems'] ?? []), 'is_array')) as $ss) {
            $name = trim((string)($ss['name'] ?? ''));
            $category = $this->category((string)($ss['category'] ?? ''), $name);
            $matched = [];

            foreach ($tables as $t) {
                if ($this->isEquipmentTable($t) && $this->tableMatchesSystem($t, $name, $category)) {
                    $matched[] = $t;
                }
            }

            $components = [];
            foreach ($matched as $t) {
                $components = array_merge($components, $this->extractComponents($t, $name, $category));
                $used[] = $t['id'];
            }
            $components = $this->uniqueComponents($components);

            foreach ($components as $c) {
                $equipment[] = [
                    'code' => $c['code'] ?? null,
                    'category' => $category,
                    'system_name' => $name ?: null,
                    'system_category' => $category,
                    'location_note' => $c['location'] ?? null,
                    'brand' => $c['brand'] ?? null,
                    'model' => $c['model'] ?? null,
                    'serial_no' => $c['serial_no'] ?? null,
                    'result' => null,
                    'note' => null,
                    'control_items' => $c['control_items'] ?? [],
                    'properties' => $c['properties'] ?? [],
                    'source_pages' => $c['source_pages'] ?? [],
                    'match' => ['status' => 'new', 'matched_id' => null, 'candidate_ids' => []],
                ];
            }

            $systems[] = [
                'name' => $name ?: null,
                'category' => $category,
                'control_count' => 0,
                'nonconforming_count' => 0,
                'components' => array_map(fn($c) => [
                    'code' => $c['code'] ?? null,
                    'name' => $c['name'] ?? null,
                    'location' => $c['location'] ?? null,
                    'brand' => $c['brand'] ?? null,
                    'model' => $c['model'] ?? null,
                    'serial_no' => $c['serial_no'] ?? null,
                ], $components),
                'equipment_matrix' => [
                    'codes' => array_values(array_map(fn($c) => $c['code'] ?? null, $components)),
                    'locations' => array_values(array_map(fn($c) => $c['location'] ?? null, $components)),
                ],
                'tables' => array_map(fn($t) => $this->uiTable($t), $matched),
                'control_items' => [],
            ];
        }

        foreach ($tables as $t) {
            if ($this->isControlTable($t)) {
                foreach ($this->extractControls($t) as $c) {
                    $controls[] = $c;
                }
            }
        }

        $controls = $this->uniqueControls($controls);
        $report = $semantic['report'] ?? [];
        $uniqueEquipment = $this->uniqueEquipment($equipment);

        return [
            'control_date' => $report['control_date'] ?? null,
            'next_control_date' => $report['next_control_date'] ?? null,
            'report_no' => $report['report_no'] ?? null,
            'company_name' => $report['company_name'] ?? null,
            'overall_result' => $report['overall_result'] ?? null,
            'covered_categories' => array_values(array_unique(array_filter(array_map(fn($s) => $s['category'] ?? null, $systems)))),
            'systems' => $systems,
            'equipment' => $uniqueEquipment,
            'control_matrix' => $controls,
            'tables' => array_values(array_map(
                fn($t) => $this->uiTable($t),
                array_filter($tables, fn($t) => !in_array($t['id'], $used, true))
            )),
            'matched_inventory_items' => [],
            'candidate_inventory_items' => [],
            'unmatched_codes' => [],
            'analyzer' => [
                'version' => '7.1.0',
                'table_count' => count($tables),
                'equipment_count' => count($uniqueEquipment),
                'control_count' => count($controls),
            ],
        ];
    }

    private function discover(array $pages): array
    {
        $tables = [];
        $id = 1;

        foreach (array_values($pages) as $pi => $page) {
            $rows = [];
            foreach (preg_split('/\R/u', (string)$page) ?: [] as $lineNo => $raw) {
                $line = trim((string)$raw);
                if ($line === '') {
                    $this->flush($tables, $rows, $id);
                    continue;
                }

                $cells = $this->splitRow($line);
                if (count($cells) >= 2 || $this->isControlLine($line) || $this->looksLikeAttribute($line)) {
                    $rows[] = ['line' => $line, 'cells' => $cells, 'page' => $pi + 1, 'line_no' => $lineNo];
                } elseif ($rows && $this->isContinuation($line, $rows[count($rows) - 1])) {
                    $rows[count($rows) - 1]['cells'][] = $line;
                    $rows[count($rows) - 1]['line'] .= ' ' . $line;
                } else {
                    $this->flush($tables, $rows, $id);
                }
            }
            $this->flush($tables, $rows, $id);
        }

        return $this->mergeAdjacent($tables);
    }

    private function flush(array &$tables, array &$rows, int &$id): void
    {
        if (count($rows) < 2) {
            $rows = [];
            return;
        }

        $max = max(array_map(fn($r) => count($r['cells']), $rows));
        $header = [];
        foreach ($rows as $r) {
            if (count($r['cells']) === $max && $max >= 2) {
                $header = $r['cells'];
                break;
            }
        }

        $tables[] = [
            'id' => $id++,
            'pages' => array_values(array_unique(array_column($rows, 'page'))),
            'header' => $header,
            'rows' => $rows,
            'text' => implode("\n", array_column($rows, 'line')),
        ];
        $rows = [];
    }

    private function splitRow(string $line): array
    {
        if (str_contains($line, '|')) {
            return $this->parts(preg_split('/\|/', $line) ?: [], true);
        }
        if (str_contains($line, "\t")) {
            return $this->parts(preg_split('/\t+/', $line) ?: [], true);
        }

        $x = preg_split('/\s{2,}/u', trim($line)) ?: [];
        if (count($x) > 1) {
            return $this->parts($x);
        }
        if ($this->looksLikeAttribute($line) && preg_match('/^(.+?)\s+(.+)$/u', $line, $m)) {
            return [$this->clean($m[1]), ...$this->parts(preg_split('/\s+/u', $m[2]) ?: [])];
        }
        return [$line];
    }

    private function isContinuation(string $line, array $previous): bool
    {
        $label = $this->normalizeKey($previous['cells'][0] ?? '');
        if (!in_array($label, self::LOCATION_LABELS, true)) {
            return false;
        }
        return !preg_match('/^(?:No|Kod|Kat|Marka|Model|Seri|Dolap|Uzunluk|Tasar|Basın|Basin|Debi|Çap|Cap|Hortum|Pompa|Yakıt|Güç|Devreye)\b/iu', $line);
    }

    private function mergeAdjacent(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            $i = count($out) - 1;
            if ($i >= 0 && min($t['pages']) - max($out[$i]['pages']) <= 1 && $this->sameStructure($out[$i], $t)) {
                $out[$i]['rows'] = array_merge($out[$i]['rows'], $t['rows']);
                $out[$i]['pages'] = array_values(array_unique(array_merge($out[$i]['pages'], $t['pages'])));
                $out[$i]['text'] .= "\n" . $t['text'];
            } else {
                $out[] = $t;
            }
        }
        return $out;
    }

    private function sameStructure(array $a, array $b): bool
    {
        if ($this->normalizeKey(implode('|', $a['header'])) === $this->normalizeKey(implode('|', $b['header'])) && $a['header']) {
            return true;
        }
        return $this->isEquipmentTable($a) && $this->isEquipmentTable($b) && abs(count($a['header']) - count($b['header'])) <= 1;
    }

    private function isEquipmentTable(array $t): bool
    {
        foreach ($t['rows'] as $r) {
            $label = $this->normalizeKey($r['cells'][0] ?? '');
            if (in_array($label, self::IDENTITY_LABELS, true)) {
                return true;
            }
            if (preg_match('/\bYD\s*[- ]?\s*\d+[A-Z]?\b/iu', $r['line'])) {
                return true;
            }
        }
        return false;
    }

    private function isControlTable(array $t): bool
    {
        // A table is a control table only when actual control-code segments
        // contain U/UD/N results. Decimal values such as 10.04 or 2026.2027
        // are therefore not enough to classify a table as a control table.
        $withStatus = 0;
        foreach ($t['rows'] as $r) {
            foreach ($this->parseControlSegments($r['line']) as $segment) {
                if (!empty($segment['statuses'])) {
                    $withStatus++;
                }
            }
        }
        return $withStatus >= 2;
    }

    private function isControlLine(string $line): bool
    {
        return preg_match('/^\s*\d+\.\d+(?:\.|\s)/u', $line) === 1;
    }

    private function controlCodeCount(string $line): int
    {
        preg_match_all('/(?<!\d)(\d+\.\d+)(?=\s|\.|$)/u', $line, $m);
        return count($m[1]);
    }

    private function looksLikeAttribute(string $line): bool
    {
        return preg_match('/^(?:[A-Z]\s+)?(?:No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri(?:\s+No)?|Dolap No|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Yer|Uzunluk|Tasarım Basıncı|Basınç|Basinç|Debi|Çap|Cap|Hortum Tipi|Tip|Tür|Tur|Pompa No|Ekipman No|Yakıt|Güç|Devreye girme)/iu', $line) === 1;
    }

    private function tableMatchesSystem(array $t, string $name, string $category): bool
    {
        $text = $this->normalizeKey($t['text']);
        $hits = 0;
        foreach (array_merge($this->tokens($name), $this->categoryTerms($category)) as $term) {
            if (mb_strlen($term, 'UTF-8') >= 4 && str_contains($text, $term)) {
                $hits++;
            }
        }
        if ($this->hasCodeForCategory($t, $category)) {
            $hits += 2;
        }
        return $hits >= 2 || ($hits >= 1 && $this->hasIdentityRow($t));
    }

    private function hasCodeForCategory(array $t, string $category): bool
    {
        if ($category === 'yangin_dolabi') {
            return preg_match('/\bYD\s*[- ]?\s*\d+[A-Z]?\b/iu', $t['text']) === 1 || str_contains($this->normalizeKey($t['text']), 'dolap no');
        }
        return $category === 'yangin_pompasi' && str_contains($this->normalizeKey($t['text']), 'pompa no');
    }

    private function hasIdentityRow(array $t): bool
    {
        foreach ($t['rows'] as $r) {
            if (in_array($this->normalizeKey($r['cells'][0] ?? ''), self::IDENTITY_LABELS, true)) {
                return true;
            }
        }
        return false;
    }

    private function extractComponents(array $table, string $systemName, string $category): array
    {
        $identity = null;
        foreach ($table['rows'] as $r) {
            $label = $this->normalizeKey($r['cells'][0] ?? '');
            if (in_array($label, self::IDENTITY_LABELS, true) || preg_match('/\bYD\s*[- ]?\s*\d+[A-Z]?\b/iu', $r['line'])) {
                $identity = $r;
                break;
            }
        }
        if (!$identity) {
            return [];
        }

        $codes = $this->identityValues($identity);
        if (!$codes) {
            return [];
        }

        $n = count($codes);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = [
                'code' => $codes[$i],
                'name' => $systemName ?: null,
                'location' => null,
                'brand' => null,
                'model' => null,
                'serial_no' => null,
                'properties' => [],
                'control_items' => [],
                'source_pages' => $table['pages'],
            ];
        }

        foreach ($table['rows'] as $row) {
            $label = $this->label($row['cells'][0] ?? '');
            if ($label === null) {
                continue;
            }
            $key = $this->normalizeKey($label);
            if (in_array($key, self::IDENTITY_LABELS, true)) {
                continue;
            }

            if ($this->isControlLine($row['line']) || $this->controlCodeCount($row['line']) > 0) {
                $segments = $this->parseControlSegments($row['line']);
                if ($segments) {
                    foreach ($out as $i => &$item) {
                        $values = $this->controlStatusValues($segments, $n, $i);
                        foreach ($values as $cc => $v) {
                            if ($v !== null) {
                                $item['control_items'][$cc] = $v;
                            }
                        }
                    }
                    unset($item);
                    continue;
                }
            }

            $values = $this->rowValues($row, $n, $key);
            foreach ($out as $i => &$item) {
                $v = $this->clean($values[$i] ?? null);
                if ($v === null) {
                    continue;
                }
                if (in_array($key, self::LOCATION_LABELS, true)) {
                    $item['location'] = $v;
                } elseif (in_array($key, self::BRAND_LABELS, true)) {
                    $item['brand'] = $v;
                } elseif (in_array($key, self::MODEL_LABELS, true)) {
                    $item['model'] = $v;
                } elseif (in_array($key, self::SERIAL_LABELS, true)) {
                    $item['serial_no'] = $v;
                } elseif (!$this->isUnitOnly($v)) {
                    $item['properties'][$key] = $v;
                }
            }
            unset($item);
        }

        return $out;
    }

    private function identityValues(array $row): array
    {
        $values = [];
        foreach (array_slice($row['cells'], 1) as $cell) {
            $cell = trim((string)$cell);
            if (preg_match_all('/\bYD\s*[- ]?\s*\d+[A-Z]?\b/iu', $cell, $m)) {
                foreach ($m[0] as $v) {
                    $values[] = strtoupper(preg_replace('/\s+|-/', '', $v));
                }
            } elseif (preg_match('/^\d{1,4}[A-Z]?$/u', $cell)) {
                $values[] = $cell;
            } elseif ($this->normalizeKey($row['cells'][0] ?? '') === 'pompa no' && $cell !== '') {
                $values[] = $cell;
            }
        }
        return array_values(array_unique($values));
    }

    private function rowValues(array $row, int $n, string $key): array
    {
        $cells = array_values(array_slice($row['cells'], 1));
        $joined = implode(' ', $cells);

        if (preg_match_all('/P\s*:\s*(.*?)\s+U\s*:\s*(.*?)(?=\s+P\s*:|$)/iu', $joined, $m, PREG_SET_ORDER)) {
            $v = [];
            foreach ($m as $p) {
                $v[] = 'P: ' . trim($p[1]) . ' U: ' . trim($p[2]);
            }
            if (count($v) >= $n) {
                return array_slice($v, 0, $n);
            }
        }

        if (in_array($key, self::LOCATION_LABELS, true)) {
            $loc = $this->locationValues($joined, $n);
            if (count($loc) >= $n) {
                return array_slice($loc, 0, $n);
            }
        }

        if (count($cells) === $n) {
            return $cells;
        }
        if (count($cells) > $n) {
            return array_slice($cells, 0, $n);
        }

        return $this->pad(preg_split('/\s+/u', trim($joined)) ?: [], $n);
    }

    private function locationValues(string $joined, int $n): array
    {
        $joined = trim(preg_replace('/\s+/u', ' ', $joined));
        if ($joined === '') {
            return [];
        }
        preg_match_all('/(?:-?\d+\.?\s*KAT|ZEM[İI]N|BODRUM|ÇATI|GİRİŞ|GIRIS|ASMA KAT)/iu', $joined, $m);
        if (count($m[0]) >= $n) {
            return array_slice(array_map(fn($x) => $this->clean($x), $m[0]), 0, $n);
        }
        return array_fill(0, $n, $joined);
    }

    /**
     * Bir satırda 5.1 ... UD 5.20 ... UD gibi birden fazla kontrol olabilir.
     * Her kodu kendi segmentine ayırırız. Böylece açıklamaya komşu kolonlar karışmaz.
     */
    private function parseControlSegments(string $line): array
    {
        preg_match_all('/(?<!\d)(\d+\.\d+)(?=\s|\.|$)/u', $line, $m, PREG_OFFSET_CAPTURE);
        if (!$m[1]) {
            return [];
        }

        $segments = [];
        $count = count($m[1]);
        for ($i = 0; $i < $count; $i++) {
            $code = $m[1][$i][0];
            $start = $m[1][$i][1] + strlen($code);
            $end = $i + 1 < $count ? $m[1][$i + 1][1] : strlen($line);
            $body = trim(substr($line, $start, $end - $start));

            preg_match_all('/\b(?:UD|U|N)\b/iu', $body, $sm, PREG_OFFSET_CAPTURE);
            $statuses = [];
            foreach ($sm[0] as $s) {
                $statuses[] = [
                    'value' => strtoupper($s[0]),
                    'offset' => $s[1],
                ];
            }

            $firstStatusOffset = $statuses[0]['offset'] ?? strlen($body);
            $description = trim(substr($body, 0, $firstStatusOffset));
            $description = preg_replace('/\s+/u', ' ', $description);

            $segments[] = [
                'code' => $code,
                'description' => trim((string)$description),
                'statuses' => array_values(array_map(fn($s) => $s['value'], $statuses)),
            ];
        }

        return $segments;
    }

    private function controlStatusValues(array $segments, int $n, int $componentIndex): array
    {
        $out = [];
        foreach ($segments as $segment) {
            $statuses = $segment['statuses'] ?? [];
            if (!$statuses) {
                $out[$segment['code']] = null;
                continue;
            }

            if (count($statuses) === 1) {
                $out[$segment['code']] = $statuses[0];
            } elseif (isset($statuses[$componentIndex])) {
                $out[$segment['code']] = $statuses[$componentIndex];
            } else {
                $out[$segment['code']] = null;
            }
        }
        return $out;
    }

    private function extractControls(array $table): array
    {
        $out = [];
        foreach ($table['rows'] as $r) {
            if ($this->controlCodeCount($r['line']) === 0) {
                continue;
            }

            foreach ($this->parseControlSegments($r['line']) as $segment) {
                // A decimal/reference number without a U/UD/N result is not a control.
                if (empty($segment['statuses'])) {
                    continue;
                }

                $results = [];
                $non = 0;
                foreach (array_values($segment['statuses']) as $i => $v) {
                    $v = strtoupper($v);
                    $results[(string)($i + 1)] = $v;
                    if ($v === 'UD') {
                        $non++;
                    }
                }

                $out[] = [
                    'control_code' => $segment['code'],
                    'description' => $segment['description'],
                    'results' => $results,
                    'nonconforming_count' => $non,
                    'source_pages' => $r['page'] ? [$r['page']] : [],
                ];
            }
        }
        return $out;
    }

    private function controlCode(string $line): ?string
    {
        return preg_match('/^\s*(\d+\.\d+)/u', $line, $m) ? $m[1] : null;
    }

    private function uiTable(array $t): array
    {
        return [
            'table_id' => $t['id'],
            'pages' => $t['pages'],
            'headers' => $t['header'],
            'rows' => array_map(fn($r) => $r['cells'], $t['rows']),
            'raw_text' => $t['text'],
        ];
    }

    private function uniqueComponents(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $x) {
            $k = ($x['code'] ?? '') . '|' . ($x['location'] ?? '') . '|' . ($x['brand'] ?? '');
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = 1;
            $out[] = $x;
        }
        return $out;
    }

    private function uniqueEquipment(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $x) {
            $k = ($x['category'] ?? '') . '|' . ($x['code'] ?? '') . '|' . ($x['location_note'] ?? '') . '|' . ($x['brand'] ?? '') . '|' . ($x['serial_no'] ?? '');
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = 1;
            $out[] = $x;
        }
        return $out;
    }

    private function uniqueControls(array $items): array
    {
        $groups = [];

        foreach ($items as $x) {
            $results = $x['results'] ?? [];
            if (!is_array($results) || !$results) {
                continue;
            }

            $code = trim((string)($x['control_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $description = trim((string)($x['description'] ?? ''));
            $key = $code . '|' . $this->normalizeKey($description);

            if (!isset($groups[$key])) {
                $groups[$key] = $x;
                $groups[$key]['results'] = [];
                $groups[$key]['nonconforming_count'] = 0;
                $groups[$key]['source_pages'] = [];
            }

            foreach ($results as $result) {
                $value = strtoupper(trim((string)$result));
                if (!in_array($value, ['U', 'UD', 'N'], true)) {
                    continue;
                }
                $next = (string)(count($groups[$key]['results']) + 1);
                $groups[$key]['results'][$next] = $value;
                if ($value === 'UD') {
                    $groups[$key]['nonconforming_count']++;
                }
            }

            $groups[$key]['source_pages'] = array_values(array_unique(array_merge(
                $groups[$key]['source_pages'],
                (array)($x['source_pages'] ?? [])
            )));
        }

        $out = array_values(array_filter($groups, fn($x) => !empty($x['results'])));
        usort($out, function ($a, $b) {
            $cmp = version_compare((string)($a['control_code'] ?? '0'), (string)($b['control_code'] ?? '0'));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp(
                $this->normalizeKey((string)($a['description'] ?? '')),
                $this->normalizeKey((string)($b['description'] ?? ''))
            );
        });
        return $out;
    }

    private function category(string $category, string $name): string
    {
        $c = $this->normalizeKey($category);
        $n = $this->normalizeKey($name);
        $allowed = ['yangin_dolabi','sprinkler','hidrant','yangin_pompasi','su_deposu','gazli_sondurme','diger'];
        if (in_array($c, $allowed, true)) {
            return $c;
        }
        if (str_contains($n, 'pompa')) return 'yangin_pompasi';
        if (str_contains($n, 'dolap') || str_contains($n, 'hortum')) return 'yangin_dolabi';
        if (str_contains($n, 'hidrant') || str_contains($n, 'itfaiye')) return 'hidrant';
        if (str_contains($n, 'sprinkler') || str_contains($n, 'yagmurlama')) return 'sprinkler';
        if (str_contains($n, 'depo')) return 'su_deposu';
        if (str_contains($n, 'gazli')) return 'gazli_sondurme';
        return 'diger';
    }

    private function categoryTerms(string $category): array
    {
        return match ($category) {
            'yangin_dolabi' => ['yangin','dolap','hortum','yd'],
            'yangin_pompasi' => ['yangin','pompa','pompa no'],
            'sprinkler' => ['sprinkler','yagmurlama'],
            'hidrant' => ['hidrant','itfaiye'],
            'su_deposu' => ['su','depo'],
            'gazli_sondurme' => ['gazli','sondurme'],
            default => ['belge','kayit','proje'],
        };
    }

    private function tokens(string $value): array
    {
        $value = $this->normalizeKey($value);
        return array_values(array_filter(preg_split('/\s+/u', $value) ?: [], fn($v) => mb_strlen($v, 'UTF-8') >= 4));
    }

    private function label(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        return preg_replace('/\s+/u', ' ', $value);
    }

    private function parts(array $parts, bool $keepEmpty = false): array
    {
        $out = [];
        foreach ($parts as $part) {
            $v = $this->clean($part);
            if ($v !== null || $keepEmpty) {
                $out[] = $v ?? '';
            }
        }
        return $out;
    }

    private function clean($value): ?string
    {
        if ($value === null) return null;
        $v = trim((string)$value);
        $v = preg_replace('/\s+/u', ' ', $v);
        return $v === '' || $v === '-' ? null : $v;
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $map = ['ç'=>'c','ğ'=>'g','ı'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','â'=>'a','î'=>'i','û'=>'u'];
        return strtr($value, $map);
    }

    private function pad(array $values, int $n): array
    {
        while (count($values) < $n) $values[] = null;
        return array_slice($values, 0, $n);
    }

    private function isUnitOnly(string $value): bool
    {
        return preg_match('/^(?:U|UD|N)$/iu', trim($value)) === 1;
    }
}
