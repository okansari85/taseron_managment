<?php

namespace App\Services\Ai;

/**
 * Firma/rapor şablonundan bağımsız tablo çıkarıcı.
 *
 * Gemini'nin anlamsal çıktısını kaynak kabul eder; ekipman, teknik kolonlar,
 * devam tabloları ve U/UD/N matrisini PDF metninden deterministik olarak çıkarır.
 */
class UniversalFireSuppressionTableAnalyzer
{
    public function analyze(array $pages, array $semantic): array
    {
        $physicalTables = $this->discoverTables($pages);
        $logicalTables = $this->mergeContinuationTables($physicalTables);
        $systems = is_array($semantic['systems'] ?? null) ? $semantic['systems'] : [];

        $systemResults = [];
        $allEquipment = [];
        $allControls = [];
        $usedTableIds = [];

        foreach ($systems as $system) {
            if (!is_array($system)) {
                continue;
            }

            $name = trim((string) ($system['name'] ?? ''));
            $category = $this->category((string) ($system['category'] ?? ''), $name);
            $matched = $this->matchTables($logicalTables, $name, $category);
            $usedTableIds = array_merge($usedTableIds, array_column($matched, 'id'));

            $components = [];
            $matrix = ['codes' => [], 'locations' => []];
            $controlItems = [];
            $tableData = [];

            foreach ($matched as $table) {
                $tableData[] = $this->tableForUi($table);
                $controlItems = array_merge($controlItems, $this->extractControls($table));

                if ($category === 'yangin_dolabi') {
                    $matrix = $this->mergeMatrix($matrix, $this->extractEquipmentMatrix($table));
                    $components = array_merge($components, $this->matrixComponents($matrix, $name, $category));
                } else {
                    $components = array_merge($components, $this->extractComponents($table, $category));
                }
            }

            $components = $this->uniqueComponents($components);
            $controlItems = $this->uniqueControls($controlItems);

            if ($category === 'yangin_dolabi') {
                $components = $this->matrixComponents($matrix, $name, $category);
            }

            foreach ($components as $component) {
                $allEquipment[] = [
                    'code' => $component['code'] ?? $component['name'] ?? null,
                    'category' => $category,
                    'system_name' => $name !== '' ? $name : null,
                    'system_category' => $category,
                    'location_note' => $component['location'] ?? null,
                    'brand' => $component['brand'] ?? null,
                    'model' => $component['model'] ?? null,
                    'serial_no' => $component['serial_no'] ?? null,
                    'result' => null,
                    'note' => null,
                    'control_items' => [],
                    'properties' => $component['properties'] ?? [],
                    'source_pages' => $component['source_pages'] ?? [],
                ];
            }

            foreach ($controlItems as $control) {
                $control['system_name'] = $name !== '' ? $name : null;
                $control['category'] = $category;
                $allControls[] = $control;
            }

            $systemResults[] = [
                'name' => $name !== '' ? $name : null,
                'category' => $category,
                'control_count' => count($controlItems),
                'nonconforming_count' => count(array_filter($controlItems, fn ($c) => ($c['nonconforming_count'] ?? 0) > 0)),
                'components' => $components,
                'equipment_matrix' => $matrix,
                'tables' => $tableData,
                'control_items' => $controlItems,
            ];
        }

        // Gemini bir sistem başlığını kaçırmış olsa bile, güçlü tablo sinyali
        // taşıyan ve hiçbir sisteme bağlanmayan tabloları kaybetme.
        $orphanTables = [];
        foreach ($logicalTables as $table) {
            if (in_array($table['id'], $usedTableIds, true)) {
                continue;
            }
            if ($this->isRelevantFireTable($table)) {
                $orphanTables[] = $this->tableForUi($table);
            }
        }

        $systemResults = $this->uniqueSystems($systemResults);
        $allEquipment = $this->uniqueComponents($allEquipment);
        $allControls = $this->uniqueControls($allControls);

        return [
            'systems' => $systemResults,
            'equipment' => $allEquipment,
            'control_matrix' => $allControls,
            'tables' => $orphanTables,
            'analyzer' => [
                'version' => '1.0.0',
                'table_count' => count($logicalTables),
                'equipment_count' => count($allEquipment),
                'control_count' => count($allControls),
            ],
        ];
    }

    private function discoverTables(array $pages): array
    {
        $tables = [];
        $id = 1;

        foreach (array_values($pages) as $pageIndex => $page) {
            $lines = preg_split('/\R/u', (string) $page) ?: [];
            $current = [];
            $start = null;

            foreach ($lines as $lineIndex => $line) {
                $line = trim(preg_replace('/[ \t]+/u', ' ', $line));
                if ($line === '') {
                    if (count($current) >= 2) {
                        $tables[] = $this->makeTable($id++, $pageIndex + 1, $start ?? $lineIndex, $current);
                    }
                    $current = [];
                    $start = null;
                    continue;
                }

                $cells = $this->splitRow($line);
                $tableLike = count($cells) >= 2 || $this->isAttributeRow($line) || $this->isControlRow($line);

                if ($tableLike) {
                    $start ??= $lineIndex;
                    $current[] = ['line' => $line, 'cells' => $cells, 'page' => $pageIndex + 1];
                } elseif (count($current) >= 2) {
                    $tables[] = $this->makeTable($id++, $pageIndex + 1, $start ?? $lineIndex, $current);
                    $current = [];
                    $start = null;
                }
            }

            if (count($current) >= 2) {
                $tables[] = $this->makeTable($id++, $pageIndex + 1, $start ?? 0, $current);
            }
        }

        return $tables;
    }

    private function makeTable(int $id, int $page, int $start, array $rows): array
    {
        $header = [];
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, count($row['cells']));
        }
        foreach ($rows as $row) {
            if (count($row['cells']) >= $max && $max >= 2) {
                $header = $row['cells'];
                break;
            }
        }

        return [
            'id' => $id,
            'pages' => [$page],
            'start_line' => $start,
            'header' => $header,
            'rows' => $rows,
            'text' => implode("\n", array_column($rows, 'line')),
        ];
    }

    private function mergeContinuationTables(array $tables): array
    {
        $logical = [];
        foreach ($tables as $table) {
            $merged = false;
            $lastIndex = count($logical) - 1;

            if ($lastIndex >= 0) {
                $last = $logical[$lastIndex];
                if ($this->looksLikeContinuation($last, $table)) {
                    $last['rows'] = array_merge($last['rows'], $table['rows']);
                    $last['pages'] = array_values(array_unique(array_merge($last['pages'], $table['pages'])));
                    $last['text'] .= "\n" . $table['text'];
                    if (count($last['header']) < count($table['header'])) {
                        $last['header'] = $table['header'];
                    }
                    $logical[$lastIndex] = $last;
                    $merged = true;
                }
            }

            if (! $merged) {
                $logical[] = $table;
            }
        }

        return $logical;
    }

    private function looksLikeContinuation(array $a, array $b): bool
    {
        $aHeader = $this->headerSignature($a['header']);
        $bHeader = $this->headerSignature($b['header']);
        $pageGap = min($b['pages']) - max($a['pages']);

        if ($pageGap > 1) {
            return false;
        }
        if ($aHeader !== '' && $aHeader === $bHeader) {
            return true;
        }

        $aCols = count($a['header']);
        $bCols = count($b['header']);
        if ($aCols > 1 && $bCols > 1 && abs($aCols - $bCols) <= 1) {
            $aTokens = $this->tokens($a['text']);
            $bTokens = $this->tokens($b['text']);
            return count(array_intersect($aTokens, $bTokens)) >= 2
                || $this->hasEquipmentCode($a['text']) && $this->hasEquipmentCode($b['text']);
        }

        // Header olmayan devam sayfası: önceki tablonun veri biçimi devam ediyor.
        return $this->hasEquipmentCode($a['text']) && $this->hasEquipmentCode($b['text'])
            && count($b['header']) >= 2;
    }

    private function matchTables(array $tables, string $systemName, string $category): array
    {
        $nameTokens = $this->tokens($systemName);
        $matched = [];

        foreach ($tables as $table) {
            $text = mb_strtolower($table['text'], 'UTF-8');
            $score = 0;
            foreach ($nameTokens as $token) {
                if (mb_strlen($token) >= 3 && str_contains($text, $token)) {
                    $score += 2;
                }
            }

            $categoryWords = match ($category) {
                'yangin_dolabi' => ['dolap', 'yd', 'hortum'],
                'yangin_pompasi' => ['pompa', 'jokey', 'debi', 'basınç'],
                'hidrant' => ['hidrant'],
                'sprinkler' => ['sprinkler', 'yağmurlama'],
                'su_deposu' => ['depo', 'hacim', 'su seviyesi'],
                'su_alma_verme' => ['su alma', 'itfaiye su', 'verme ağz'],
                'sabit_boru_tesisati' => ['boru', 'kollektör', 'vana'],
                'gazli_sondurme' => ['gazlı', 'fm200', 'co2'],
                default => [],
            };
            foreach ($categoryWords as $word) {
                if (str_contains($text, $word)) {
                    $score++;
                }
            }

            if ($this->hasEquipmentCode($table['text'])) {
                $score += 2;
            }
            if ($this->hasTechnicalColumns($table)) {
                $score += 2;
            }
            if ($this->hasControlMarkers($table['text'])) {
                $score += 1;
            }

            if ($score >= max(2, min(4, count($nameTokens)))) {
                $matched[] = $table;
            }
        }

        return $matched;
    }

    private function extractEquipmentMatrix(array $table): array
    {
        $codes = [];
        $locations = [];
        $rows = $table['rows'];

        // Yatay tablo: ilk anlamlı satır kod başlığıdır.
        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                if (preg_match('/\b(?:YD[- ]?)\d+[A-Z]?\b/iu', $cell, $m)) {
                    $codes[] = strtoupper(str_replace(' ', '', $m[0]));
                }
            }
            if (count($codes) >= 2) {
                break;
            }
        }

        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            $codes = $this->numberedHeaderCodes($rows);
        }

        if ($codes === []) {
            return ['codes' => [], 'locations' => []];
        }

        $attributeRows = [];
        foreach ($rows as $row) {
            $label = $this->cellLabel($row['cells'][0] ?? '');
            if ($label === null) {
                continue;
            }
            $attributeRows[$this->normalizeKey($label)] = array_slice($row['cells'], 1);
        }

        $locationValues = $this->findAttribute($attributeRows, ['kat', 'bulundugu yer', 'bulunduğu yer', 'lokasyon', 'konum', 'yer']);
        if ($locationValues !== []) {
            foreach ($codes as $index => $_code) {
                $locations[] = $this->cleanValue($locationValues[$index] ?? null);
            }
        } else {
            $locations = array_fill(0, count($codes), null);
        }

        return [
            'codes' => $codes,
            'locations' => $locations,
        ];
    }

    private function matrixComponents(array $matrix, string $systemName, string $category): array
    {
        $items = [];
        foreach ($matrix['codes'] as $i => $code) {
            $items[] = [
                'code' => $code,
                'name' => 'Yangın Dolabı',
                'location' => $matrix['locations'][$i] ?? null,
                'brand' => null,
                'model' => null,
                'serial_no' => null,
                'properties' => [],
                'source_pages' => [],
            ];
        }
        return $items;
    }

    private function extractComponents(array $table, string $category): array
    {
        $rows = $table['rows'];
        if ($rows === []) {
            return [];
        }

        // Transposed tablo: "Marka X X", "Model A B", "Seri ...".
        $attributeRows = [];
        foreach ($rows as $row) {
            $label = $this->cellLabel($row['cells'][0] ?? '');
            if ($label !== null && count($row['cells']) >= 2) {
                $attributeRows[$this->normalizeKey($label)] = array_slice($row['cells'], 1);
            }
        }

        $codeValues = $this->findAttribute($attributeRows, ['kod', 'no', 'ekipman no', 'pompa no', 'hidrant no', 'cihaz no', 'etiket']);
        $nameValues = $this->findAttribute($attributeRows, ['ekipman', 'ekipman adi', 'ekipman adı', 'tip', 'tür', 'tur', 'pompa']);
        $locationValues = $this->findAttribute($attributeRows, ['kat', 'bulundugu yer', 'bulunduğu yer', 'lokasyon', 'konum', 'yer']);
        $brandValues = $this->findAttribute($attributeRows, ['marka', 'üretici', 'uretici']);
        $modelValues = $this->findAttribute($attributeRows, ['model', 'model no']);
        $serialValues = $this->findAttribute($attributeRows, ['seri no', 'serino', 'seri numarasi', 'seri numarası']);

        $technical = $this->technicalAttributes($attributeRows);
        $count = max(count($codeValues), count($nameValues), count($locationValues), count($brandValues), count($modelValues), count($serialValues));

        if ($count >= 1) {
            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $item = [
                    'code' => $this->cleanValue($codeValues[$i] ?? null),
                    'name' => $this->cleanValue($nameValues[$i] ?? null),
                    'location' => $this->cleanValue($locationValues[$i] ?? null),
                    'brand' => $this->cleanValue($brandValues[$i] ?? null),
                    'model' => $this->cleanValue($modelValues[$i] ?? null),
                    'serial_no' => $this->cleanValue($serialValues[$i] ?? null),
                    'properties' => $this->columnAt($technical, $i),
                    'source_pages' => $table['pages'],
                ];
                if ($item['code'] !== null || $item['name'] !== null || $item['location'] !== null) {
                    $items[] = $item;
                }
            }
            if ($items !== []) {
                return $items;
            }
        }

        // Klasik tablo: header + veri satırları.
        $header = array_map(fn ($v) => $this->normalizeKey($v), $table['header']);
        if (count($header) < 2) {
            return [];
        }
        $items = [];
        foreach ($rows as $index => $row) {
            if ($index === 0 || count($row['cells']) < 2) {
                continue;
            }
            $assoc = [];
            foreach ($header as $i => $key) {
                if ($key !== '') {
                    $assoc[$key] = $this->cleanValue($row['cells'][$i] ?? null);
                }
            }
            if ($this->looksLikeControlAssoc($assoc)) {
                continue;
            }
            $code = $this->pick($assoc, ['kod', 'no', 'ekipman no', 'pompa no', 'hidrant no', 'etiket']);
            $name = $this->pick($assoc, ['ekipman', 'ekipman adi', 'ekipman adı', 'tip', 'tur', 'tür', 'pompa']);
            $location = $this->pick($assoc, ['kat', 'bulundugu yer', 'bulunduğu yer', 'lokasyon', 'konum', 'yer']);
            if ($code === null && $name === null && $location === null) {
                continue;
            }
            $items[] = [
                'code' => $code,
                'name' => $name,
                'location' => $location,
                'brand' => $this->pick($assoc, ['marka', 'uretici', 'üretici']),
                'model' => $this->pick($assoc, ['model', 'model no']),
                'serial_no' => $this->pick($assoc, ['seri no', 'serino', 'seri numarasi', 'seri numarası']),
                'properties' => $assoc,
                'source_pages' => $table['pages'],
            ];
        }
        return $items;
    }

    private function extractControls(array $table): array
    {
        $controls = [];
        foreach ($table['rows'] as $row) {
            if (! $this->isControlRow($row['line'])) {
                continue;
            }
            $cells = $row['cells'];
            $label = trim((string) ($cells[0] ?? $row['line']));
            preg_match('/^(\d+(?:\.\d+)*\.?)/u', $label, $m);
            $code = $m[1] ?? null;
            $results = [];
            foreach (array_slice($cells, 1) as $i => $cell) {
                $status = $this->controlStatus($cell);
                if ($status !== null) {
                    $results[(string) ($i + 1)] = $status;
                }
            }
            $statusTokens = preg_match_all('/\b(?:U|UD|N)\b/iu', $row['line'], $matches) ? $matches[0] : [];
            if ($results === [] && $statusTokens === []) {
                continue;
            }
            $controls[] = [
                'control_code' => $code,
                'description' => $code !== null ? trim(preg_replace('/^\d+(?:\.\d+)*\.?\s*/u', '', $label)) : $label,
                'results' => $results,
                'nonconforming_count' => count(array_filter($results, fn ($v) => $v === 'UD')),
                'source_pages' => $table['pages'],
            ];
        }
        return $controls;
    }

    private function tableForUi(array $table): array
    {
        return [
            'table_id' => $table['id'],
            'pages' => $table['pages'],
            'headers' => $table['header'],
            'rows' => array_map(fn ($r) => $r['cells'], $table['rows']),
            'raw_text' => $table['text'],
        ];
    }

    private function mergeMatrix(array $a, array $b): array
    {
        $codes = $a['codes'];
        $locations = $a['locations'];
        foreach ($b['codes'] as $i => $code) {
            $key = array_search($code, $codes, true);
            if ($key === false) {
                $codes[] = $code;
                $locations[] = $b['locations'][$i] ?? null;
            } elseif (($locations[$key] ?? null) === null && isset($b['locations'][$i])) {
                $locations[$key] = $b['locations'][$i];
            }
        }
        return ['codes' => $codes, 'locations' => $locations];
    }

    private function uniqueComponents(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $key = mb_strtolower(implode('|', [
                $item['code'] ?? '', $item['name'] ?? '', $item['location'] ?? '', $item['brand'] ?? '', $item['model'] ?? '', $item['serial_no'] ?? '',
            ]), 'UTF-8');
            if ($key === '|||||' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    private function uniqueControls(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $key = mb_strtolower(($item['system_name'] ?? '') . '|' . ($item['control_code'] ?? '') . '|' . ($item['description'] ?? ''), 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    private function uniqueSystems(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $key = mb_strtolower((string) ($item['name'] ?? '') . '|' . (string) ($item['category'] ?? ''), 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    private function splitRow(string $line): array
    {
        $line = trim($line);
        if (str_contains($line, '|')) {
            return array_values(array_filter(array_map('trim', preg_split('/\|/u', $line) ?: []), fn ($v) => $v !== ''));
        }
        if (str_contains($line, "\t")) {
            return array_values(array_filter(array_map('trim', preg_split('/\t+/u', $line) ?: []), fn ($v) => $v !== ''));
        }
        $cells = preg_split('/\s{2,}/u', $line) ?: [];
        return array_values(array_filter(array_map('trim', $cells), fn ($v) => $v !== ''));
    }

    private function isAttributeRow(string $line): bool
    {
        return (bool) preg_match('/^(No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri|Seri No|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Uzunluk|Basınç|Basinç|Tasarım|Debi|Çap|Cap|Tip|Tür|Tur)\b/iu', trim($line));
    }

    private function isControlRow(string $line): bool
    {
        return (bool) preg_match('/(?:^|\s)\d+(?:\.\d+)+\.?\s+.+?(?:\s|^)(?:U|UD|N)(?:\s|$)/iu', trim($line));
    }

    private function hasControlMarkers(string $text): bool
    {
        return preg_match('/\b(?:U|UD|N)\b/iu', $text) === 1;
    }

    private function hasEquipmentCode(string $text): bool
    {
        return preg_match('/\b(?:YD[- ]?\d+|H[- ]?\d+|P[- ]?\d+|HD[- ]?\d+|\d{1,4})\b/iu', $text) === 1;
    }

    private function hasTechnicalColumns(array $table): bool
    {
        return preg_match('/marka|model|seri|basınç|basinc|uzunluk|debi|çap|cap|kat|lokasyon|konum|hortum|tip|tür|tur/iu', implode(' ', $table['header'])) === 1;
    }

    private function isRelevantFireTable(array $table): bool
    {
        return $this->hasEquipmentCode($table['text']) || $this->hasControlMarkers($table['text']) || $this->hasTechnicalColumns($table);
    }

    private function numberedHeaderCodes(array $rows): array
    {
        foreach ($rows as $row) {
            $cells = $row['cells'];
            if (count($cells) < 3) {
                continue;
            }
            $values = array_slice($cells, 1);
            $numeric = array_values(array_filter($values, fn ($v) => preg_match('/^\d{1,4}[A-Z]?$/u', trim($v))));
            if (count($numeric) >= 2 && count($numeric) / max(1, count($values)) > 0.5) {
                return $numeric;
            }
        }
        return [];
    }

    private function cellLabel(string $cell): ?string
    {
        $cell = trim($cell);
        if ($cell === '') {
            return null;
        }
        if (preg_match('/^(No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri(?: No)?|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Yer|Uzunluk|Tasarım Basıncı|Basınç|Basinç|Debi|Çap|Cap|Hortum Tipi|Tip|Tür|Tur|Pompa No|Ekipman No)\b/iu', $cell)) {
            return $cell;
        }
        return null;
    }

    private function findAttribute(array $rows, array $aliases): array
    {
        foreach ($aliases as $alias) {
            $key = $this->normalizeKey($alias);
            foreach ($rows as $rowKey => $values) {
                if ($rowKey === $key || str_contains($rowKey, $key) || str_contains($key, $rowKey)) {
                    return array_values($values);
                }
            }
        }
        return [];
    }

    private function technicalAttributes(array $rows): array
    {
        $skip = ['kod', 'no', 'ekipman no', 'ekipman adi', 'ekipman adı', 'kat', 'bulundugu yer', 'bulunduğu yer', 'lokasyon', 'konum', 'yer', 'marka', 'uretici', 'üretici', 'model', 'model no', 'seri no', 'serino', 'seri numarasi', 'seri numarası'];
        $out = [];
        foreach ($rows as $key => $values) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            if (count($values) >= 1) {
                $out[$key] = array_values($values);
            }
        }
        return $out;
    }

    private function columnAt(array $attributes, int $index): array
    {
        $out = [];
        foreach ($attributes as $key => $values) {
            if (array_key_exists($index, $values)) {
                $out[$key] = $values[$index];
            }
        }
        return $out;
    }

    private function pick(array $assoc, array $keys): ?string
    {
        foreach ($keys as $key) {
            $key = $this->normalizeKey($key);
            foreach ($assoc as $actual => $value) {
                if ($actual === $key || str_contains($actual, $key) || str_contains($key, $actual)) {
                    return $this->cleanValue($value);
                }
            }
        }
        return null;
    }

    private function looksLikeControlAssoc(array $assoc): bool
    {
        return count(array_filter(array_values($assoc), fn ($v) => $this->controlStatus($v) !== null)) >= 1;
    }

    private function controlStatus(?string $value): ?string
    {
        $v = mb_strtoupper(trim((string) $value), 'UTF-8');
        return in_array($v, ['U', 'UD', 'N'], true) ? $v : null;
    }

    private function cleanValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return $value === '' || $value === '-' ? null : $value;
    }

    private function normalizeKey(string $value): string
    {
        $v = mb_strtolower(trim($value), 'UTF-8');
        $v = strtr($v, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c']);
        $v = preg_replace('/[^a-z0-9]+/u', ' ', $v) ?? $v;
        return trim($v);
    }

    private function headerSignature(array $header): string
    {
        return implode('|', array_map(fn ($v) => $this->normalizeKey($v), $header));
    }

    private function tokens(string $value): array
    {
        $value = $this->normalizeKey($value);
        return array_values(array_unique(array_filter(explode(' ', $value), fn ($v) => mb_strlen($v) >= 3)));
    }

    private function category(string $category, string $name): string
    {
        $v = $this->normalizeKey($category . ' ' . $name);
        return match (true) {
            str_contains($v, 'dolap') => 'yangin_dolabi',
            str_contains($v, 'pompa') => 'yangin_pompasi',
            str_contains($v, 'hidrant') => 'hidrant',
            str_contains($v, 'sprinkler') || str_contains($v, 'yagmurlama') => 'sprinkler',
            str_contains($v, 'su depo') || str_contains($v, 'su dep') => 'su_deposu',
            str_contains($v, 'su alma') || str_contains($v, 'su verme') => 'su_alma_verme',
            str_contains($v, 'boru') || str_contains($v, 'kollektor') => 'sabit_boru_tesisati',
            str_contains($v, 'gazli') || str_contains($v, 'fm200') || str_contains($v, 'co2') => 'gazli_sondurme',
            default => $category !== '' ? $category : 'diger',
        };
    }
}
