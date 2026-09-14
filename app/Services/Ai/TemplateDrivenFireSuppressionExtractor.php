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
        $extractedSystems = [];

        foreach ($systems as $system) {
            if (!is_array($system)) continue;
            $systemName = $this->string($system['system_name'] ?? null);
            if ($systemName === null) continue;
            $equipment = [];
            foreach ((array) ($system['equipment'] ?? []) as $equipmentTemplate) {
                if (!is_array($equipmentTemplate)) continue;
                foreach ($this->extractHorizontalEquipment($latticeTables, $equipmentTemplate) as $item) $equipment[] = $item;
            }
            $extractedSystems[] = [
                'system_name' => $systemName,
                'equipment' => $equipment,
                'control_items' => $this->extractControls($latticeTables, (array) ($system['control_items'] ?? [])),
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

    private function extractHorizontalEquipment(array $tables, array $template): array
    {
        $headerPatterns = array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['equipment_header_patterns'] ?? []))));
        $labelPatterns = array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['left_column_patterns'] ?? $template['table_structure']['left_column']['label_patterns'] ?? []))));
        if (!$headerPatterns || !$labelPatterns) return [];

        $items = [];
        $currentHeader = [];
        foreach ($tables as $table) {
            foreach ($this->matrix($table) as $row) {
                if (!$row) continue;
                $headerColumn = $this->findPatternColumn($row, $headerPatterns);
                if ($headerColumn !== null) {
                    $currentHeader = $this->headerFromRow($row, $headerColumn, $template);
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
            $codePatterns = array_values(array_filter(array_map('strval', (array) ($control['control_code_patterns'] ?? []))));
            $resultPatterns = array_values(array_filter(array_map('strval', (array) ($control['result_patterns'] ?? []))));
            if (!$codePatterns) continue;
            foreach ($tables as $table) {
                foreach ($this->matrix($table) as $row) {
                    foreach ($row as $index => $value) {
                        $code = $this->matchPatternValue((string) $value, $codePatterns);
                        if ($code === null) continue;
                        $result = null;
                        foreach ($row as $resultIndex => $candidate) {
                            if ((int) $resultIndex === (int) $index) continue;
                            $matched = $this->matchPatternValue((string) $candidate, $resultPatterns);
                            if ($matched !== null) { $result = $matched; break; }
                        }
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
                    if ($key === null || !$patterns) continue;
                    $labelColumn = $this->findPatternColumn($row, $patterns);
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
                    if ($key === null || !$patterns) continue;
                    $labelColumn = $this->findPatternColumn($row, $patterns);
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
        $active = !$sectionPatterns; $textParts = []; $status = null;
        foreach ($tables as $table) {
            if (($table['flavor'] ?? '') !== 'stream') continue;
            foreach ($this->matrix($table) as $row) {
                $text = $this->rowText($row);
                if ($sectionPatterns && $this->matchesAny($text, $sectionPatterns)) { $active = true; continue; }
                if (!$active) continue;
                if ($text !== '') $textParts[] = $text;
                $matchedStatus = $this->matchPatternValue($text, $statusPatterns);
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

    private function findPatternColumn(array $row, array $patterns): ?int
    {
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
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
