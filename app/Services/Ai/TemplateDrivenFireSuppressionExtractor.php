<?php

namespace App\Services\Ai;

use RuntimeException;

class TemplateDrivenFireSuppressionExtractor
{
    public function __construct(private CamelotPdfTableExtractor $camelot) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        $camelot = $this->camelot->extract($pdfPath);
        $allTables = array_values(array_filter((array) ($camelot['tables'] ?? []), fn ($table) => is_array($table) && !empty($table['data'])));
        $latticeTables = array_values(array_filter($allTables, fn ($table) => ($table['flavor'] ?? '') === 'lattice'));
        if (!$latticeTables) throw new RuntimeException('Camelot template extraction için kullanılabilir lattice tablo bulamadı.');

        $template = is_array($semantic['template'] ?? null) ? $semantic['template'] : [];
        $systems = (array) ($template['fire_systems']['systems'] ?? []);
        $allSystemSectionPatterns = $this->collectSystemSectionPatterns($systems);
        $extractedSystems = [];

        foreach ($systems as $system) {
            if (!is_array($system)) continue;
            $systemName = $this->string($system['system_name'] ?? null);
            if ($systemName === null) continue;

            $controlTemplates = (array) ($system['control_items'] ?? []);
            $equipment = [];
            foreach ((array) ($system['equipment'] ?? []) as $equipmentTemplate) {
                if (!is_array($equipmentTemplate)) continue;
                $orientation = mb_strtolower(trim((string) ($equipmentTemplate['table_structure']['orientation'] ?? '')), 'UTF-8');
                $extractedEquipment = $orientation === 'vertical_key_value'
                    ? $this->extractVerticalKeyValueEquipment($latticeTables, $equipmentTemplate)
                    : $this->extractHorizontalEquipment(
                        $latticeTables,
                        $equipmentTemplate,
                        $controlTemplates,
                        $system['section_heading_patterns'] ?? [],
                        $allSystemSectionPatterns
                    );
                foreach ($extractedEquipment as $item) {
                    $equipment[] = $item;
                }
            }

            $extractedSystems[] = [
                'system_name' => $systemName,
                'equipment' => $equipment,
                'control_items' => $this->extractControls($latticeTables, $controlTemplates),
            ];
        }

        $result = [
            'extracted_data' => [
                'report_information' => $this->extractReportInformation($allTables, (array) ($template['report_information']['fields'] ?? [])),
                'facility_or_project_information' => $this->extractFacilityInformation($allTables, (array) ($template['facility_or_project_information'] ?? [])),
                'fire_systems' => $extractedSystems,
                'overall_result' => $this->extractOverallResult($allTables, (array) ($template['overall_result'] ?? [])),
                'findings' => (array) ($semantic['extracted_data']['findings'] ?? []),
            ],
        ];

        $result = $this->sanitizeUtf8($result);
        $invalidPath = $this->findInvalidUtf8Path($result);
        if ($invalidPath !== null) throw new RuntimeException('Geçersiz UTF-8 çıktı alanı: ' . $invalidPath);
        return $result;
    }

    private function extractHorizontalEquipment(
        array $tables,
        array $template,
        array $controlTemplates = [],
        array $currentSectionPatterns = [],
        array $allSystemSectionPatterns = []
    ): array {
        // camelot_extraction.equipment_header_patterns is sometimes filled with the
        // SECTION heading (e.g. "7.1. YANGIN DOLABI LİSTESİ") instead of the actual
        // in-table column header that introduces the equipment codes (e.g. "Dolap
        // No") - that never matches any table row, so no header row is ever found
        // and every row gets silently skipped. equipment_identity.header_patterns is
        // meant for exactly this and has proven reliable across different reports,
        // so merge it in as a fallback/complement rather than trusting only one field.
        $headerPatterns = array_values(array_unique(array_merge(
            array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['equipment_header_patterns'] ?? [])))),
            array_values(array_filter(array_map('strval', (array) ($template['equipment_identity']['header_patterns'] ?? [])))),
        )));
        // Same reliability gap as above: camelot_extraction.left_column_patterns can
        // be an incomplete subset of table_structure.left_column.label_patterns (a
        // real label missing from one but present in the other) - merge both rather
        // than picking one via ?? and silently losing whichever labels only the
        // other one has.
        $labelPatterns = array_values(array_unique(array_merge(
            array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['left_column_patterns'] ?? [])))),
            array_values(array_filter(array_map('strval', (array) ($template['table_structure']['left_column']['label_patterns'] ?? []))))
        )));
        if (!$headerPatterns || !$labelPatterns) return [];

        $identityPatterns = array_values(array_filter(array_map('strval', (array) ($template['equipment_identity']['identity_patterns'] ?? []))));

        $controlCodePatterns = [];
        foreach ($controlTemplates as $control) {
            foreach ((array) ($control['control_code_patterns'] ?? []) as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern !== '') $controlCodePatterns[] = $pattern;
            }
        }
        $currentSectionPatterns = array_values(array_filter(array_map('strval', (array) $currentSectionPatterns)));
        $allSystemSectionPatterns = array_values(array_filter(array_map('strval', (array) $allSystemSectionPatterns)));

        $items = [];
        $currentHeader = [];
        // Equipment numbering can legitimately restart at 1 in a new block (e.g.
        // a new building/location section) - codes are NOT guaranteed unique
        // across the whole document. Key items by (block, code) internally so a
        // later block's "1" doesn't silently overwrite an earlier block's "1";
        // the output 'code' field itself stays the plain, unprefixed value.
        $blockIndex = 0;
        foreach ($tables as $table) {
            foreach ($this->matrix($table) as $row) {
                if (!$row) continue;
                $rowText = $this->rowText($row);

                if ($allSystemSectionPatterns && $this->matchesAny($rowText, $allSystemSectionPatterns)) {
                    $isCurrentSection = !$currentSectionPatterns || $this->matchesAny($rowText, $currentSectionPatterns);
                    if (!$isCurrentSection) {
                        $currentHeader = [];
                        continue;
                    }
                }

                if ($controlCodePatterns && $this->rowContainsControlCode($row, $controlCodePatterns)) {
                    $currentHeader = [];
                    continue;
                }

                $headerColumn = $this->findPatternColumn($row, $headerPatterns);
                if ($headerColumn !== null) {
                    $header = $this->headerFromRow($row, $headerColumn, $template);
                    // A genuine repeating-block header always establishes several
                    // equipment codes at once (5 typically, at least 2 for a
                    // trailing partial block) - a header candidate with only ONE
                    // matching column is too fragile to trust (e.g. a location cell
                    // that happens to be nothing but "1", coincidentally short
                    // enough to pass the word-count check above) and is more likely
                    // a coincidence than a real header row.
                    if (count($header) >= 2) { $currentHeader = $header; $blockIndex++; continue; }
                    // A pattern meant to flag the header row can, in some reports,
                    // ALSO legitimately label a genuine per-item data row (e.g.
                    // "Bulunduğu Yer" both marks context and holds real location
                    // text per item). If no equipment identity codes were actually
                    // found here, this wasn't really a header row - fall through and
                    // process it as data instead of silently discarding it.
                }

                if (!$currentHeader) continue;

                $labelColumn = $this->findPatternColumn($row, $labelPatterns);
                if ($labelColumn === null) continue;

                // A row whose own data values ALL look like equipment identity
                // codes themselves (e.g. a repeating "Soru / Kriter" sub-header
                // whose "values" are a meaningless running row counter, not real
                // per-item content) is a structural/duplicate artifact, not
                // genuine data - skip it rather than recording those numbers as a
                // bogus property. A real property (brand, location, a measurement)
                // won't look like a set of equipment codes.
                if ($this->rowLooksLikeIdentityCodes($row, $currentHeader, $labelColumn, $identityPatterns)) continue;
                $label = $this->cleanValue((string) $row[$labelColumn]);
                foreach ($currentHeader as $column => $codes) {
                    if ($column <= $labelColumn) continue;
                    $value = trim((string) ($row[$column] ?? ''));
                    if ($value === '' || $value === '-') continue;
                    // A header cell can list the SAME code twice as two distinct
                    // physical columns (a real data-entry duplicate in the source,
                    // e.g. "...17 18 18 19..." - two separate dolaplar both marked
                    // "18"). Key by column position too, not just block+code, so
                    // two same-valued columns in the same block never collide.
                    foreach ($codes as $code) {
                        $key = $this->itemKey($template, $blockIndex . '#' . $column . '#' . $code);
                        $items[$key]['code'] = $code;
                        $items[$key]['name'] = $this->string($template['equipment_name'] ?? null);
                        $items[$key]['system_name'] = $this->string($template['system_name'] ?? null);
                        $items[$key]['properties'][$label] = $this->cleanValue($value);
                        $items[$key]['source_pages'][] = (int) ($table['page'] ?? 0);
                    }
                }
            }
        }
        foreach ($items as &$item) $item['source_pages'] = array_values(array_unique(array_filter(array_map('intval', (array) ($item['source_pages'] ?? [])))));
        unset($item);
        return array_values($items);
    }

    /**
     * "vertical_key_value" orientation: each equipment instance is its own small
     * lattice table (a paired label:value grid, e.g. "Marka | CLARKE | Tip - Model | DİZEL"
     * on one row, "Pompa Seri No | 1" on another), not a shared table with one column per
     * equipment. Gemini's equipment_identity.identity_patterns lists the instances in
     * reading order (e.g. "1 NUMARALI POMPA", "2 NUMARALI POMPA", "JOKEY POMPA") - each
     * matching lattice table is paired with the identity pattern at the same position,
     * in top-to-bottom document order.
     */
    private function extractVerticalKeyValueEquipment(array $latticeTables, array $template): array
    {
        $leftLabels = array_values(array_filter(array_map('strval', (array) (
            $template['camelot_extraction']['left_column_patterns']
            ?? $template['table_structure']['left_column']['label_patterns']
            ?? []
        ))));
        $rightLabels = array_values(array_filter(array_map('strval', (array) (
            $template['camelot_extraction']['right_column_patterns']
            ?? $template['table_structure']['right_column']['header_patterns']
            ?? []
        ))));
        $identityPatterns = array_values(array_filter(array_map('strval', (array) ($template['equipment_identity']['identity_patterns'] ?? []))));
        if (!$leftLabels || !$identityPatterns) return [];

        $valueLabels = array_values(array_unique(array_merge($leftLabels, $rightLabels)));

        $blocks = [];
        foreach ($latticeTables as $table) {
            $rows = $this->matrix($table);
            if (!$rows) continue;
            $firstLabel = $this->cleanValue((string) ($rows[0][0] ?? ''));
            if (!$this->matchesAny($firstLabel, $leftLabels)) continue;

            // A table can coincidentally start with one of our labels (e.g. a
            // diesel-pump fuel-tank table also has "Marka" as its first row) without
            // actually being one of this equipment's blocks - require most of the
            // declared LEFT (label) column patterns to actually show up in the
            // block before accepting it. Deliberately checked against $leftLabels
            // only, not the merged $valueLabels: the right column's patterns are
            // often a bare wildcard (".*", matching any value cell by design), and
            // counting against that would count every row as "matched" regardless
            // of whether it's really this equipment's own label.
            $matchedLabels = 0;
            foreach ($rows as $row) {
                for ($column = 0; $column + 1 < count($row); $column += 2) {
                    $label = $this->cleanValue((string) ($row[$column] ?? ''));
                    if ($label !== '' && $this->matchesAny($label, $leftLabels)) $matchedLabels++;
                }
            }
            if ($matchedLabels < max(2, (int) ceil(count($leftLabels) / 2))) continue;

            $blocks[] = ['table' => $table, 'rows' => $rows, 'y' => (float) ($table['bbox'][1] ?? 0)];
        }
        if (!$blocks) return [];

        usort($blocks, function (array $a, array $b): int {
            $pageA = (int) ($a['table']['page'] ?? 0);
            $pageB = (int) ($b['table']['page'] ?? 0);
            if ($pageA !== $pageB) return $pageA <=> $pageB;
            return $b['y'] <=> $a['y']; // higher y = closer to the page top = read first
        });

        // A non-numeric identity pattern (e.g. "JOKEY POMPA") repeats the equipment's own
        // name as a word inside it - strip whatever the template calls this equipment
        // (from equipment_name, not a hardcoded word) to leave just the distinguishing
        // part ("JOKEY"), so this works for any equipment type/vendor wording.
        $equipmentNameTokens = array_values(array_filter(
            preg_split('/\s+/u', mb_strtoupper((string) ($template['equipment_name'] ?? ''), 'UTF-8')) ?: []
        ));

        $codes = [];
        foreach ($identityPatterns as $pattern) {
            if (preg_match('/(\d+)/u', $pattern, $match) === 1) { $codes[] = $match[1]; continue; }
            // Gemini may express the identity pattern as a self-anchored regex
            // (e.g. "^Jokey$") rather than a literal phrase - strip the anchors
            // before treating what's left as the code, or they leak into the
            // final output verbatim.
            $remaining = mb_strtoupper(preg_replace('/^\^|\$$/u', '', $pattern) ?? $pattern, 'UTF-8');
            foreach ($equipmentNameTokens as $token) {
                $remaining = trim(preg_replace('/\b' . preg_quote($token, '/') . '\b/ui', '', $remaining) ?? $remaining);
            }
            $codes[] = $remaining !== '' ? $remaining : trim($pattern);
        }

        $items = [];
        foreach ($blocks as $index => $block) {
            $code = $codes[$index] ?? (string) ($index + 1);
            $properties = [];
            foreach ($block['rows'] as $row) {
                for ($column = 0; $column + 1 < count($row); $column += 2) {
                    $label = $this->cleanValue((string) ($row[$column] ?? ''));
                    $value = trim((string) ($row[$column + 1] ?? ''));
                    if ($label === '' || $value === '' || $value === '-') continue;
                    if (!$this->matchesAny($label, $valueLabels)) continue;
                    $properties[$label] = $this->cleanValue($value);
                }
            }
            if (!$properties) continue;
            $items[] = [
                'code' => $code,
                'name' => $this->string($template['equipment_name'] ?? null),
                'system_name' => $this->string($template['system_name'] ?? null),
                'properties' => $properties,
                'source_pages' => array_values(array_unique(array_filter([(int) ($block['table']['page'] ?? 0)]))),
            ];
        }
        return $items;
    }

    private function rowLooksLikeIdentityCodes(array $row, array $currentHeader, int $labelColumn, array $identityPatterns): bool
    {
        if (!$identityPatterns) return false;
        $checked = 0;
        foreach ($currentHeader as $column => $codes) {
            if ($column <= $labelColumn) continue;
            $value = trim((string) ($row[$column] ?? ''));
            if ($value === '' || $value === '-') continue;
            $checked++;
            if (!$this->matchesAny($value, $identityPatterns)) return false;
        }
        return $checked > 0;
    }

    private function headerFromRow(array $row, int $headerColumn, array $template): array
    {
        $header = [];
        $identityPatterns = array_values(array_filter(array_map('strval', (array) ($template['equipment_identity']['identity_patterns'] ?? []))));
        foreach ($row as $column => $value) {
            if ((int) $column <= $headerColumn) continue;
            // A genuine identity cell IS the code (maybe with a short prefix, e.g.
            // "62-63-...-74" or "YD 5") - reject a cell that's really a multi-word
            // sentence with a number merely embedded in it (e.g. "TV FABRİKA E 5
            // GİRİŞ TARAFI", "SOLAR BİNA KAT 1" - a location mentioning a floor or
            // door number). Otherwise property/location rows get mistaken for a
            // fresh header row and wrongly reset the real one mid-block.
            $wordCount = count(preg_split('/\s+/u', trim((string) $value)) ?: []);
            if ($wordCount > 2) continue;
            $tokens = $this->expandEquipmentCodes((string) $value, $identityPatterns);
            if ($tokens) $header[(int) $column] = $tokens;
        }
        return $header;
    }

    private function expandEquipmentCodes(string $value, array $identityPatterns = []): array
    {
        $value = trim(str_replace(["\n", "\r"], ' ', $value));
        if ($value === '' || $value === '-') return [];
        // A dash-joined numeric list/range can be broken across a PDF line-wrap right
        // after a dash (e.g. "62-63-...-69- 70-71-...-74"); collapse that first so the
        // whole sequence stays one token instead of two truncated halves.
        $value = preg_replace('/-\s+/u', '-', $value) ?? $value;
        $tokens = [];
        foreach (preg_split('/\s+/u', $value) ?: [] as $token) {
            $token = trim($token, " ,;");
            if ($token === '') continue;
            if ($identityPatterns && $this->matchesAny($token, $identityPatterns)) {
                foreach ($this->expandNumericCodeSequence($token) as $code) $tokens[] = $code;
                continue;
            }
            if (!$identityPatterns && preg_match('/^\d+(?:-\d+)*$/u', $token)) {
                foreach ($this->expandNumericCodeSequence($token) as $code) $tokens[] = $code;
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * A token like "62-63-64-...-74" already lists every code (dash used as a
     * separator between consecutive items) - split it into its numbers as-is. A
     * token with exactly two dash-joined numbers, e.g. "10-20", is a range
     * shorthand meaning every code from the first to the second inclusive.
     * A token without a dash-joined numeric sequence (e.g. "YD1", "Jokey") is
     * returned unchanged.
     */
    private function expandNumericCodeSequence(string $token): array
    {
        if (preg_match('/^([A-ZÇĞİÖŞÜa-z]*)(\d+(?:-\d+)+)$/u', $token, $match) !== 1) {
            return [$token];
        }
        $prefix = $match[1];
        $numbers = array_values(array_filter(explode('-', $match[2]), fn ($part) => $part !== ''));
        if (count($numbers) < 2) return [$token];

        if (count($numbers) === 2) {
            [$start, $end] = array_map('intval', $numbers);
            if ($start > $end) [$start, $end] = [$end, $start];
            $expanded = [];
            for ($number = $start; $number <= $end; $number++) $expanded[] = $prefix . $number;
            return $expanded;
        }

        return array_map(static fn ($number) => $prefix . $number, $numbers);
    }

private function extractControls(array $tables, array $controlTemplates): array
{
    $out = [];

    foreach ($controlTemplates as $control) {
        if (!is_array($control)) continue;

        $codePatterns = array_values(array_filter(
            array_map('strval', (array) ($control['control_code_patterns'] ?? []))
        ));

        $resultPatterns = array_values(array_filter(
            array_map('strval', (array) ($control['result_patterns'] ?? []))
        ));

        if (!$codePatterns) continue;

        foreach ($tables as $table) {

            // EKLENDİ
            $cells = (array) ($table['cells'] ?? []);

            // rowIndex EKLENDİ
            foreach ($this->matrix($table) as $rowIndex => $row) {

                // columnIndex EKLENDİ
                foreach ($row as $columnIndex => $value) {

                    $code = $this->matchControlCode(
                        (string) $value,
                        $codePatterns
                    );

                    if ($code === null) continue;

                    $result = null;

                    // Scan forward from this code's own column only - a row can hold
                    // more than one code/criterion/result group side by side (e.g.
                    // "5.19 | Borular | U | 5.37 ... | | N"), and scanning the whole
                    // row from index 0 would grab a NEIGHBORING code's result instead
                    // of this one's. Stop as soon as another cell looks like a control
                    // code itself - that means we've crossed into the next group
                    // without finding this code's own result.
                    for ($resultIndex = (int) $columnIndex + 1; $resultIndex < count($row); $resultIndex++) {
                        $candidate = (string) ($row[$resultIndex] ?? '');

                        if ($this->matchControlCode($candidate, $codePatterns) !== null) {
                            break;
                        }

                        $matched = $this->matchResultValue($candidate, $resultPatterns);

                        if ($matched !== null) {
                            $result = $matched;
                            break;
                        }
                    }

                    /*
                     * Kriteri Gemini'den değil,
                     * Camelot'un PDF hücrelerinden bul.
                     */
                    $criterion = $this->findCriterionFromCells(
                        $cells,
                        (int) $rowIndex,
                        (int) $columnIndex,
                        $code,
                        $codePatterns,
                        $resultPatterns
                    );

                    $out[$this->normalizeCode($code)] = [
                        'code' => $code,
                        'criterion' => $criterion,
                        'result' => $result,
                        'source_pages' => array_values(
                            array_unique(
                                array_filter([
                                    (int) ($table['page'] ?? 0)
                                ])
                            )
                        ),
                    ];
                }
            }
        }
    }

    return array_values($out);
}



private function findCriterionFromCells(
    array $cells,
    int $controlRow,
    int $controlColumn,
    string $code,
    array $codePatterns,
    array $resultPatterns
): ?string {
    $controlCell = $cells[$controlRow][$controlColumn] ?? null;

    if (!is_array($controlCell)) {
        return null;
    }

    $controlText = trim((string) ($controlCell['text'] ?? ''));

    if ($controlText === '') {
        return null;
    }

    $controlX1 = (float) ($controlCell['x1'] ?? 0);
    $controlX2 = (float) ($controlCell['x2'] ?? 0);
    $controlY1 = (float) ($controlCell['y1'] ?? 0);
    $controlY2 = (float) ($controlCell['y2'] ?? 0);

    /*
     * Önce kontrol hücresinin içinde criterion var mı?
     *
     * Örnek:
     * 5.1 Proje varlığı ve onayı
     */
    $sameCellCriterion = trim(
        preg_replace(
            '/^' . preg_quote($code, '/') . '\s*[:.)-]?\s*/iu',
            '',
            $controlText
        ) ?? $controlText
    );

    if (
        $sameCellCriterion !== ''
        && $sameCellCriterion !== $controlText
        && $this->normalizeCode($sameCellCriterion) !== $this->normalizeCode($code)
    ) {
        return $sameCellCriterion;
    }

    /*
     * ============================================================
     * KOORDİNAT TABANLI KRİTER ARAMA
     * ============================================================
     */

    $horizontalCandidates = [];
    $verticalCandidates = [];

    foreach ($cells as $rowIndex => $rowCells) {
        foreach ($rowCells as $columnIndex => $cell) {

            if (!is_array($cell)) {
                continue;
            }

            $text = trim((string) ($cell['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            /*
             * Kontrol hücresinin kendisi
             */
            if (
                (int) $rowIndex === $controlRow
                && (int) $columnIndex === $controlColumn
            ) {
                continue;
            }

            /*
             * Başka bir kontrol kodunu criterion olarak alma.
             */
            $isControlCode = false;

            foreach ($codePatterns as $pattern) {
                if (
                    $this->matchControlCode(
                        $text,
                        [$pattern]
                    ) !== null
                ) {
                    $isControlCode = true;
                    break;
                }
            }

            if ($isControlCode) {
                continue;
            }

            /*
             * Sonuç hücresini criterion olarak alma.
             */
            if (
                $this->matchResultValue(
                    $text,
                    $resultPatterns
                ) !== null
            ) {
                continue;
            }

            $x1 = (float) ($cell['x1'] ?? 0);
            $x2 = (float) ($cell['x2'] ?? 0);
            $y1 = (float) ($cell['y1'] ?? 0);
            $y2 = (float) ($cell['y2'] ?? 0);

            /*
             * ====================================================
             * 1. YATAY KOMŞULUK
             *
             * [5.1] [Proje varlığı ve onayı] [UD]
             *
             * Kontrolün sağındaki hücreleri değerlendir.
             * ====================================================
             */

            $verticalOverlap = min($controlY2, $y2)
                - max($controlY1, $y1);

            if (
                $verticalOverlap > 0
                && $x1 >= $controlX2
            ) {
                $distance = $x1 - $controlX2;

                $horizontalCandidates[] = [
                    'text' => $text,
                    'distance' => $distance,
                    'overlap' => $verticalOverlap,
                ];

                continue;
            }

            /*
             * ====================================================
             * 2. DİKEY KOMŞULUK
             *
             * [5.1]
             * [Proje varlığı ve onayı]
             *
             * X ekseninde hizalıysa değerlendir.
             * ====================================================
             */

            $horizontalOverlap = min($controlX2, $x2)
                - max($controlX1, $x1);

            if ($horizontalOverlap <= 0) {
                continue;
            }

            /*
             * Kontrolün hemen altında
             */
            if ($y1 >= $controlY2) {

                $verticalCandidates[] = [
                    'text' => $text,
                    'distance' => $y1 - $controlY2,
                    'overlap' => $horizontalOverlap,
                ];

                continue;
            }

            /*
             * Kontrolün hemen üstünde
             */
            if ($y2 <= $controlY1) {

                $verticalCandidates[] = [
                    'text' => $text,
                    'distance' => $controlY1 - $y2,
                    'overlap' => $horizontalOverlap,
                ];
            }
        }
    }

    /*
     * ============================================================
     * YATAY ADAYLAR
     *
     * En yakın hücreyi seç.
     * ============================================================
     */

    if ($horizontalCandidates !== []) {

        usort(
            $horizontalCandidates,
            function (array $a, array $b): int {

                /*
                 * Önce yakınlık.
                 * Eşitse daha fazla dikey örtüşme.
                 */
                if ($a['distance'] == $b['distance']) {
                    return $b['overlap'] <=> $a['overlap'];
                }

                return $a['distance'] <=> $b['distance'];
            }
        );

        return trim(
            $horizontalCandidates[0]['text']
        );
    }

    /*
     * ============================================================
     * DİKEY ADAYLAR
     * ============================================================
     */

    if ($verticalCandidates !== []) {

        usort(
            $verticalCandidates,
            function (array $a, array $b): int {

                if ($a['distance'] == $b['distance']) {
                    return $b['overlap'] <=> $a['overlap'];
                }

                return $a['distance'] <=> $b['distance'];
            }
        );

        return trim(
            $verticalCandidates[0]['text']
        );
    }

    return null;
}












/*
    private function extractControls(array $tables, array $controlTemplates): array
    {
        $out = [];
        foreach ($controlTemplates as $control) {
            if (!is_array($control)) continue;
            $codePatterns = array_values(array_filter(array_map('strval', (array) ($control['control_code_patterns'] ?? []))));
            $resultPatterns = array_values(array_filter(array_map('strval', (array) ($control['result_patterns'] ?? []))));
            if (!$codePatterns) continue;
            foreach ($tables as $table) {
                foreach ($this->matrix($table) as $row) {
                    foreach ($row as $index => $value) {
                        $code = $this->matchControlCode((string) $value, $codePatterns);
                        if ($code === null) continue;
                        $result = null;
                        foreach ($row as $resultIndex => $candidate) {
                            if ((int) $resultIndex === (int) $index) continue;
                            $matched = $this->matchResultValue((string) $candidate, $resultPatterns);
                            if ($matched !== null) { $result = $matched; break; }
                        }


                                /*
                            * Kriteri Gemini'den değil,
                            * Camelot'un PDF hücrelerinden bul.

                           $criterion = $this->findCriterionFromCells(
                            $cells,
                            (int) $rowIndex,
                            (int) $columnIndex,
                            $code,
                            $codePatterns,
                            $resultPatterns
                            );

                            $out[$this->normalizeCode($code)] = [
                            'code' => $code,
                            'criterion' => $criterion,
                            'result' => $result,
                            'source_pages' => array_values(
                                array_unique(
                                    array_filter([
                                        (int) ($table['page'] ?? 0)
                                    ])
                                )
                            ),
                            ];





                        /*
                        $out[$this->normalizeCode($code)] = [
                            'code' => $code,
                            'result' => $result,
                            'source_pages' => array_values(array_unique(array_filter([(int) ($table['page'] ?? 0)]))),
                        ];

                    }
                }
            }
        }
        return array_values($out);
    }
  */


     private function matchControlCode(string $value, array $patterns): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $normalizedValue = $this->normalizeCode($value);

        $extractPrefix = static function (string $text): ?string {
            if (preg_match('/^\s*([A-Za-zÇĞİÖŞÜ]{0,8}[ .\-]?\d+(?:[.\-]\d+)*)\b/u', $text, $matches) === 1) {
                return trim($matches[1]);
            }
            return null;
        };
        $valueCode = $extractPrefix($value);

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') continue;

            $normalizedPattern = $this->normalizeCode($pattern);

            if ($normalizedPattern === $normalizedValue) return $value;

            $isRegexPattern = str_contains($pattern, '^') || str_contains($pattern, '$') || str_contains($pattern, '\\') || str_contains($pattern, '[');
            if (!$isRegexPattern) continue;

            // Gemini's pattern may already be self-anchored (e.g. "^A\.[1-9]$").
            // Strip its own ^/$ before wrapping it in ours below, otherwise the
            // pattern's own "$" blocks matching anything after it - including our
            // own optional trailing punctuation - even when the code itself matches.
            $core = preg_replace('/^\^|\$$/u', '', $pattern) ?? $pattern;

            // Code alone in its own cell (with optional trailing punctuation).
            if (@preg_match('~^(?:' . $core . ')[.:\)]?$~iu', $value) === 1) return rtrim($value, '.:)');

            // Code + criterion combined in one cell (e.g. "5.12 Dizel pompa") - the
            // whole-cell check above fails since there is trailing criterion text
            // after the code. Validate just the extracted leading code against the
            // same declared pattern.
            if ($valueCode !== null && $valueCode !== $value && @preg_match('~^(?:' . $core . ')$~iu', $valueCode) === 1) return $valueCode;
        }

        return null;
    }

    private function matchResultValue(string $value, array $patterns): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $normalizedValue = $this->normalizeLabel($value);
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') continue;
            if ($normalizedValue === $this->normalizeLabel($pattern)) return $value;
            if ((str_contains($pattern, '^') || str_contains($pattern, '$') || str_contains($pattern, '\\') || str_contains($pattern, '[')) && @preg_match($pattern, $value) === 1) return $value;
            if ((str_contains($pattern, '^') || str_contains($pattern, '$') || str_contains($pattern, '\\') || str_contains($pattern, '[')) && @preg_match('~' . $pattern . '~iu', $value) === 1) return $value;
        }
        return null;
    }

    private function rowContainsControlCode(array $row, array $patterns): bool
    {
        foreach ($row as $value) {
            if ($this->matchControlCode((string) $value, $patterns) !== null) return true;
        }
        return false;
    }

    private function collectSystemSectionPatterns(array $systems): array
    {
        $patterns = [];
        foreach ($systems as $system) {
            if (!is_array($system)) continue;
            foreach ((array) ($system['section_heading_patterns'] ?? []) as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern !== '') $patterns[] = $pattern;
            }
        }
        return array_values(array_unique($patterns));
    }

    private function extractReportInformation(array $tables, array $fieldTemplates): array
    {
        // Report header info (Rapor No, Ünvanı, ...) lives in a 'stream' table for
        // some reports but in a ruled 'lattice' table for others (report-specific
        // layout, not a rule) - scan both rather than assuming one flavor, or the
        // whole block silently comes back empty for reports that use the other one.
        $result = [];
        foreach ($tables as $table) {
            $rows = $this->matrix($table);
            foreach ($rows as $rowIndex => $row) {
                $this->applyColumnAlignedFields($result, $rows, $rowIndex, $fieldTemplates);
                foreach ($fieldTemplates as $field) {
                    if (!is_array($field)) continue;
                    $key = $this->string($field['key'] ?? null);
                    $patterns = array_values(array_filter(array_map('strval', (array) ($field['label_patterns'] ?? []))));
                    if ($key === null || !$patterns || array_key_exists($key, $result)) continue;
                    $labelColumn = $this->findPatternColumn($row, $patterns, true);
                    if ($labelColumn === null) continue;
                    $value = $this->nextNonEmpty($row, $labelColumn + 1);
                    if ($value === null) $value = $this->valueFromSameCell((string) ($row[$labelColumn] ?? ''), $patterns);
                    if ($value !== null) $result[$key] = $this->cleanValue($value);
                }
            }
        }
        return $result;
    }

    private function extractFacilityInformation(array $tables, array $template): array
    {
        $result = [];
        $sectionPatterns = array_values(array_filter(array_map('strval', (array) ($template['section_heading_patterns'] ?? []))));
        $fields = (array) ($template['fields'] ?? []);
        $sectionFound = !$sectionPatterns;
        // Same reasoning as extractReportInformation() - don't assume one table
        // flavor holds this section.
        foreach ($tables as $table) {
            $rows = $this->matrix($table);
            foreach ($rows as $rowIndex => $row) {
                $text = $this->rowText($row);
                if ($sectionPatterns && $this->matchesAny($text, $sectionPatterns)) { $sectionFound = true; continue; }
                if (!$sectionFound) continue;
                $this->applyColumnAlignedFields($result, $rows, $rowIndex, $fields);
                foreach ($fields as $field) {
                    if (!is_array($field)) continue;
                    $key = $this->string($field['key'] ?? null);
                    $patterns = array_values(array_filter(array_map('strval', (array) ($field['label_patterns'] ?? []))));
                    if ($key === null || !$patterns || array_key_exists($key, $result)) continue;
                    $labelColumn = $this->findPatternColumn($row, $patterns, true);
                    if ($labelColumn === null) continue;
                    $value = $this->nextNonEmpty($row, $labelColumn + 1);
                    if ($value === null) $value = $this->valueFromSameCell((string) ($row[$labelColumn] ?? ''), $patterns);
                    if ($value !== null) $result[$key] = $this->cleanValue($value);
                }
            }
        }
        return $result;
    }

    /**
     * Some reports print report/facility fields as a header ROW (multiple field
     * labels across columns) with the actual values in a SEPARATE row directly
     * below at the same column positions - a spreadsheet-like shape, not the
     * "label | value" pairs within one row that the caller's own scan handles.
     * If this row matches 2+ of our own fields at once, treat it as that header
     * and pull values from the next row by column position instead.
     */
    private function applyColumnAlignedFields(array &$result, array $rows, int $rowIndex, array $fieldTemplates): void
    {
        if (!isset($rows[$rowIndex + 1])) return;
        $row = $rows[$rowIndex];

        // A genuine header row (values live in the NEXT row, not this one) never
        // itself contains an actual value - and report/facility fields always
        // include at least one date (report_date/control_date/validity_date). If
        // THIS row already has a date-looking cell, it's a normal "label | value"
        // row with several pairs packed into one line (e.g. "Ünvanı | 4A
        // LOJİSTİK... | Rapor Tarihi | 10.04.2026"), which the caller's own
        // same-row scan already handles - don't reinterpret it as a header, or
        // the next unrelated row's labels get grabbed as "values" instead.
        foreach ($row as $cell) {
            if (preg_match('/\b\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}\b/u', (string) $cell) === 1) return;
        }

        $headerMatches = [];
        foreach ($fieldTemplates as $field) {
            if (!is_array($field)) continue;
            $key = $this->string($field['key'] ?? null);
            $patterns = array_values(array_filter(array_map('strval', (array) ($field['label_patterns'] ?? []))));
            if ($key === null || !$patterns || array_key_exists($key, $result)) continue;
            $column = $this->findPatternColumn($row, $patterns, true);
            if ($column !== null) $headerMatches[$column] = $key;
        }

        if (count($headerMatches) < 2) return;

        $valueRow = $rows[$rowIndex + 1];
        foreach ($headerMatches as $column => $key) {
            $value = trim((string) ($valueRow[$column] ?? ''));
            if ($value !== '' && $value !== '-') $result[$key] = $this->cleanValue($value);
        }
    }

    /**
     * A label and its value can be printed in the same cell, e.g. "Rapor No:
     * PK.239.00026.01" with nothing but blank cells after it - nextNonEmpty()
     * never finds a value in a later column in that case. Strip the matched
     * label pattern (with optional trailing punctuation) as a prefix instead.
     */
    private function valueFromSameCell(string $cellText, array $patterns): ?string
    {
        $cellText = trim($cellText);
        if ($cellText === '') return null;

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') continue;

            $remaining = preg_replace(
                '/^\s*' . preg_quote($pattern, '/') . '\s*[:.\-]?\s*/iu',
                '',
                $cellText
            );

            if ($remaining !== null && $remaining !== '' && $remaining !== $cellText) {
                return trim($remaining);
            }
        }

        return null;
    }

    private function extractOverallResult(array $tables, array $template): array
    {
        $sectionPatterns = array_values(array_filter(array_map('strval', (array) ($template['section_heading_patterns'] ?? $template['camelot_extraction']['section_patterns'] ?? []))));
        // These two pattern lists serve different purposes and must stay separate:
        // - boundaryPatterns mark where the conclusion text ENDS (the next section's
        //   heading/label, e.g. "9. ONAY").
        // - extractionPatterns identify the status SENTENCE itself (e.g. ".*UYGUN
        //   DEĞİLDİR.*"), used only after collecting all candidate rows, to trim the
        //   collected text down to the relevant portion.
        // Previously both were read into one variable, with extractionPatterns taking
        // priority via ??. Since extractionPatterns matches the row that contains the
        // real status text, that row was mistaken for the START of the next section
        // and the scan broke before ever appending it - silently truncating the
        // collected text to whatever came just before the actual conclusion.
        $boundaryPatterns = array_values(array_filter(array_map('strval', (array) ($template['overall_text']['text_boundary_patterns'] ?? []))));
        $extractionPatterns = array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['text_patterns'] ?? []))));
        $statusPatterns = array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['status_patterns'] ?? $template['overall_status']['status_patterns'] ?? []))));
        $active = !$sectionPatterns;
        $textParts = [];
        $status = null;
        foreach ($tables as $table) {
            if (($table['flavor'] ?? '') !== 'stream') continue;
            foreach ($this->matrix($table) as $row) {
                $text = $this->rowText($row);
                if ($sectionPatterns && $this->matchesAny($text, $sectionPatterns)) { $active = true; continue; }
                if (!$active) continue;
                if ($this->isOverallEndBoundary($text, $boundaryPatterns)) break 2;
                if ($text !== '') $textParts[] = $text;
                $matchedStatus = $this->matchResultValue($text, $statusPatterns);
                if ($matchedStatus !== null) $status = $matchedStatus;
            }
        }
        $fullText = trim(implode(' ', $textParts));
        if ($extractionPatterns) {
            foreach ($extractionPatterns as $pattern) {
                $match = @preg_match('~' . $pattern . '~iu', $fullText, $m);
                if ($match === 1 && !empty($m[0])) { $fullText = trim($m[0]); break; }
            }
        }
        $result = [];
        if ($fullText !== '') $result['text'] = $this->cleanValue($fullText);
        if ($status !== null) $result['status'] = $status;
        return $result;
    }

    private function isOverallEndBoundary(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesAny($text, [$pattern])) return true;
        }
        if (preg_match('/^\d+\.\s+/u', trim($text)) === 1 && !preg_match('/^8\.\s+/u', trim($text))) return true;
        return false;
    }

    private function findPatternColumn(array $row, array $patterns, bool $preferExact = false): ?int
    {
        if ($preferExact) {
            foreach ($row as $index => $value) {
                $normalizedValue = $this->normalizeLabel((string) $value);
                foreach ($patterns as $pattern) {
                    $normalizedPattern = $this->normalizeLabel((string) $pattern);
                    if ($normalizedValue === $normalizedPattern) return (int) $index;
                    // The ligature artifact above can swallow trailing letters
                    // rather than just a control character (e.g. "saati" ->
                    // "saa"), so an exact match can legitimately come up one or
                    // two characters short. Accept a close prefix match too,
                    // rather than only a byte-for-byte equal string.
                    if ($normalizedValue !== '' && $normalizedPattern !== ''
                        && mb_strlen($normalizedPattern, 'UTF-8') - mb_strlen($normalizedValue, 'UTF-8') <= 2
                        && str_starts_with($normalizedPattern, $normalizedValue)
                    ) return (int) $index;
                }
            }
        }
        foreach ($row as $index => $value) if ($this->matchesAny((string) $value, $patterns)) return (int) $index;
        return null;
    }

    private function matchPatternValue(string $value, array $patterns): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        return $this->matchesAny($value, $patterns) ? $value : null;
    }

    private function rowText(array $row): string
    {
        return trim(implode(' ', array_values(array_filter(array_map('strval', $row), fn ($v) => trim($v) !== ''))));
    }

    private function matrix(array $table): array
    {
        return array_values(array_map(fn ($row) => array_map(fn ($value) => trim((string) $value), (array) $row), (array) ($table['data'] ?? [])));
    }

    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) if ($this->regexMatches($pattern, $value)) return true;
        return false;
    }

    private function regexMatches(string $pattern, string $value): bool
    {
        if (@preg_match($pattern, $value) === 1) return true;
        if (@preg_match('~' . $pattern . '~iu', $value) === 1) return true;
        return $this->normalizeLabel($pattern) === $this->normalizeLabel($value);
    }

    private function normalizeLabel(string $value): string
    {
        // Some PDFs render a "ti"/"fi"-style ligature as a stray control
        // character (observed as \x00) that swallows those letters entirely
        // (e.g. "Saati" -> "Saa" + \x00) - strip it so it doesn't block an
        // otherwise-exact label match.
        $value = preg_replace('/[\x00-\x1F]/u', '', $value) ?? $value;
        // A "label" passed in here is often actually a self-anchored regex
        // pattern (e.g. "^Muayene Tarihi ve Saati$") rather than a plain string -
        // strip the literal anchor characters so an exact/prefix comparison
        // against real (unanchored) cell text isn't defeated by them.
        $value = preg_replace('/^\^|\$$/u', '', trim($value)) ?? $value;
        $value = mb_strtolower(trim($value), 'UTF-8');
        return rtrim(preg_replace('/\s+/u', ' ', $value) ?? $value, ':');
    }

    private function normalizeCode(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? '', 'UTF-8');
    }

    private function nextNonEmpty(array $row, int $start): ?string
    {
        for ($i = $start; $i < count($row); $i++) {
            $value = trim((string) ($row[$i] ?? ''));
            if ($value !== '' && $value !== '-') return $value;
        }
        return null;
    }

    private function cleanValue(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(["\n", "\r"], ' ', $value)) ?? $value);
    }

    private function sanitizeUtf8(mixed $value): mixed
    {
        if (is_string($value)) {
            if (mb_check_encoding($value, 'UTF-8')) return $value;
            $clean = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            return $clean === false ? '' : $clean;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) $out[is_string($key) ? $this->sanitizeUtf8($key) : $key] = $this->sanitizeUtf8($item);
            return $out;
        }
        return $value;
    }

    private function findInvalidUtf8Path(mixed $value, string $path = '$'): ?string
    {
        if (is_string($value)) return mb_check_encoding($value, 'UTF-8') ? null : $path;
        if (!is_array($value)) return null;
        foreach ($value as $key => $item) {
            if (is_string($key) && !mb_check_encoding($key, 'UTF-8')) return $path . '[key]';
            $child = is_int($key) ? $path . '[' . $key . ']' : $path . '[' . json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ']';
            $bad = $this->findInvalidUtf8Path($item, $child);
            if ($bad !== null) return $bad;
        }
        return null;
    }

    private function itemKey(array $template, string $code): string
    {
        return $this->normalizeLabel((string) ($template['equipment_name'] ?? 'equipment')) . '|' . $code;
    }

    private function string(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
