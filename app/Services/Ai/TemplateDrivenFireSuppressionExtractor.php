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
                foreach ($this->extractHorizontalEquipment(
                    $latticeTables,
                    $equipmentTemplate,
                    $controlTemplates,
                    $system['section_heading_patterns'] ?? [],
                    $allSystemSectionPatterns
                ) as $item) {
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
        $headerPatterns = array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['equipment_header_patterns'] ?? []))));
        $labelPatterns = array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['left_column_patterns'] ?? $template['table_structure']['left_column']['label_patterns'] ?? []))));
        if (!$headerPatterns || !$labelPatterns) return [];

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
                    if ($header) $currentHeader = $header;
                    continue;
                }
                if (!$currentHeader) continue;

                $labelColumn = $this->findPatternColumn($row, $labelPatterns);
                if ($labelColumn === null) continue;
                $label = $this->cleanValue((string) $row[$labelColumn]);
                foreach ($currentHeader as $column => $codes) {
                    if ($column <= $labelColumn) continue;
                    $value = trim((string) ($row[$column] ?? ''));
                    if ($value === '' || $value === '-') continue;
                    foreach ($codes as $code) {
                        $key = $this->itemKey($template, $code);
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

    private function headerFromRow(array $row, int $headerColumn, array $template): array
    {
        $header = [];
        $identityPatterns = array_values(array_filter(array_map('strval', (array) ($template['equipment_identity']['identity_patterns'] ?? []))));
        foreach ($row as $column => $value) {
            if ((int) $column <= $headerColumn) continue;
            $tokens = $this->expandEquipmentCodes((string) $value, $identityPatterns);
            if ($tokens) $header[(int) $column] = $tokens;
        }
        return $header;
    }

    private function expandEquipmentCodes(string $value, array $identityPatterns = []): array
    {
        $value = trim(str_replace(["\n", "\r"], ' ', $value));
        if ($value === '' || $value === '-') return [];
        $tokens = [];
        foreach (preg_split('/\s+/u', $value) ?: [] as $token) {
            $token = trim($token, " ,;");
            if ($token === '') continue;
            if ($identityPatterns && $this->matchesAny($token, $identityPatterns)) { $tokens[] = $token; continue; }
            if (!$identityPatterns && preg_match('/^\d+$/u', $token)) $tokens[] = $token;
        }
        return array_values(array_unique($tokens));
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

                    foreach ($row as $resultIndex => $candidate) {

                        if ((int) $resultIndex === (int) $columnIndex) {
                            continue;
                        }

                        $matched = $this->matchResultValue(
                            (string) $candidate,
                            $resultPatterns
                        );

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

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') continue;

            $normalizedPattern = $this->normalizeCode($pattern);

            if ($normalizedPattern === $normalizedValue) return $value;

            if (
                (str_contains($pattern, '^') || str_contains($pattern, '$') || str_contains($pattern, '\\') || str_contains($pattern, '['))
                && @preg_match('~^(?:' . $pattern . ')$~iu', $value) === 1
            ) return $value;
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
        $result = [];
        foreach ($tables as $table) {
            if (($table['flavor'] ?? '') !== 'stream') continue;
            foreach ($this->matrix($table) as $row) {
                foreach ($fieldTemplates as $field) {
                    if (!is_array($field)) continue;
                    $key = $this->string($field['key'] ?? null);
                    $patterns = array_values(array_filter(array_map('strval', (array) ($field['label_patterns'] ?? []))));
                    if ($key === null || !$patterns || array_key_exists($key, $result)) continue;
                    $labelColumn = $this->findPatternColumn($row, $patterns, true);
                    if ($labelColumn === null) continue;
                    $value = $this->nextNonEmpty($row, $labelColumn + 1);
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
        foreach ($tables as $table) {
            if (($table['flavor'] ?? '') !== 'stream') continue;
            foreach ($this->matrix($table) as $row) {
                $text = $this->rowText($row);
                if ($sectionPatterns && $this->matchesAny($text, $sectionPatterns)) { $sectionFound = true; continue; }
                if (!$sectionFound) continue;
                foreach ($fields as $field) {
                    if (!is_array($field)) continue;
                    $key = $this->string($field['key'] ?? null);
                    $patterns = array_values(array_filter(array_map('strval', (array) ($field['label_patterns'] ?? []))));
                    if ($key === null || !$patterns || array_key_exists($key, $result)) continue;
                    $labelColumn = $this->findPatternColumn($row, $patterns, true);
                    if ($labelColumn === null) continue;
                    $value = $this->nextNonEmpty($row, $labelColumn + 1);
                    if ($value !== null) $result[$key] = $this->cleanValue($value);
                }
            }
        }
        return $result;
    }

    private function extractOverallResult(array $tables, array $template): array
    {
        $sectionPatterns = array_values(array_filter(array_map('strval', (array) ($template['section_heading_patterns'] ?? $template['camelot_extraction']['section_patterns'] ?? []))));
        $textPatterns = array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['text_patterns'] ?? $template['overall_text']['text_boundary_patterns'] ?? []))));
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
                if ($this->isOverallEndBoundary($text, $textPatterns)) break 2;
                if ($text !== '') $textParts[] = $text;
                $matchedStatus = $this->matchResultValue($text, $statusPatterns);
                if ($matchedStatus !== null) $status = $matchedStatus;
            }
        }
        $fullText = trim(implode(' ', $textParts));
        if ($textPatterns) {
            foreach ($textPatterns as $pattern) {
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
                    if ($normalizedValue === $this->normalizeLabel((string) $pattern)) return (int) $index;
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
