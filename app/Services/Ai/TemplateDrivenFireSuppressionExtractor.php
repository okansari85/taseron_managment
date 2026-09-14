<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Extracts report values from a discovered fire-suppression template.
 * Template Discovery remains the source of structure; Camelot is the source
 * of report values. This service deliberately does not use the legacy V12
 * parser/merger.
 */
class TemplateDrivenFireSuppressionExtractor
{
    public function __construct(private CamelotPdfTableExtractor $camelot) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        $camelot = $this->camelot->extract($pdfPath);
        $tables = array_values(array_filter((array) ($camelot['tables'] ?? []), fn ($table) => is_array($table) && ($table['flavor'] ?? '') === 'lattice' && !empty($table['data'])));
        if (!$tables) throw new RuntimeException('Camelot template extraction için kullanılabilir lattice tablo bulamadı.');

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
                foreach ($this->extractHorizontalEquipment($tables, $equipmentTemplate) as $item) $equipment[] = $item;
            }
            $extractedSystems[] = [
                'system_name' => $systemName,
                'equipment' => $equipment,
                'control_items' => $this->extractControls($tables, (array) ($system['control_items'] ?? [])),
            ];
        }

        return [
            'template' => $template,
            'extracted_data' => [
                'report_information' => $this->extractReportInformation($camelot),
                'facility_or_project_information' => $this->extractFacilityInformation($camelot),
                'fire_systems' => $extractedSystems,
                'overall_result' => $this->extractOverallResult($camelot),
                'findings' => (array) ($semantic['extracted_data']['findings'] ?? []),
            ],
        ];
    }

    private function findInvalidUtf8Path(mixed $value, string $path = '$'): ?string
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? null : $path;
        }

        if (!is_array($value)) return null;

        foreach ($value as $key => $item) {
            if (is_string($key) && !mb_check_encoding($key, 'UTF-8')) return $path . '[key]';
            $childPath = is_int($key) ? $path . '[' . $key . ']' : $path . '[' . json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ']';
            $invalidPath = $this->findInvalidUtf8Path($item, $childPath);
            if ($invalidPath !== null) return $invalidPath;
        }

        return null;
    }

    private function sanitizeUtf8(mixed $value): mixed
    {
        if (is_string($value)) {
            if (mb_check_encoding($value, 'UTF-8')) return $value;
            $clean = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            return $clean === false ? '' : $clean;
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key) ? $this->sanitizeUtf8($key) : $key;
                $sanitized[$safeKey] = $this->sanitizeUtf8($item);
            }
            return $sanitized;
        }

        return $value;
    }

    private function extractHorizontalEquipment(array $tables, array $template): array
    {
        $patterns = array_merge(
            array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['equipment_header_patterns'] ?? [])))),
            array_values(array_filter(array_map('strval', (array) ($template['table_structure']['left_column']['label_patterns'] ?? []))))
        );
        if (!$patterns) return [];

        $items = [];
        $currentHeader = [];
        foreach ($tables as $table) {
            foreach ($this->matrix($table) as $row) {
                if (count($row) < 3) continue;
                if ($this->matchesAny($row[1] ?? '', $patterns)) {
                    $currentHeader = $this->headerFromRow($row);
                    continue;
                }
                if (!$currentHeader) continue;
                $field = $this->equipmentField($this->normalizeLabel($row[1] ?? ''));
                if ($field === null) continue;
                foreach ($currentHeader as $column => $codes) {
                    $value = trim((string) ($row[$column] ?? ''));
                    if ($value === '' || $value === '-') continue;
                    foreach ($codes as $code) {
                        $key = $this->itemKey($template, $code);
                        $items[$key]['code'] = $code;
                        $items[$key]['name'] = $this->string($template['equipment_name'] ?? null);
                        $items[$key]['system_name'] = $this->string($template['system_name'] ?? null);
                        $items[$key][$field] = $this->cleanValue($value);
                        $items[$key]['source_pages'][] = (int) ($table['page'] ?? 0);
                    }
                }
            }
        }
        foreach ($items as &$item) {
            $item['source_pages'] = array_values(array_unique(array_filter(array_map('intval', (array) ($item['source_pages'] ?? [])))));
            $item['properties'] = $item['properties'] ?? [];
        }
        unset($item);
        return array_values($items);
    }

    private function headerFromRow(array $row): array
    {
        $header = [];
        foreach (array_slice($row, 2, null, true) as $column => $value) {
            $tokens = $this->expandEquipmentCodes((string) $value);
            if ($tokens) $header[(int) $column] = $tokens;
        }
        return $header;
    }

    private function expandEquipmentCodes(string $value): array
    {
        $value = trim(str_replace(["\n", "\r"], ' ', $value));
        if ($value === '' || $value === '-') return [];
        $tokens = [];
        foreach (preg_split('/\s+/u', $value) ?: [] as $token) {
            $token = trim($token, " ,;");
            if ($token === '') continue;
            $compact = preg_replace('/\s+/u', '', $token) ?? $token;
            if (preg_match('/^\d+(?:-\d+)+$/u', $compact)) {
                foreach (explode('-', $compact) as $number) $tokens[] = $number;
                continue;
            }
            if (preg_match('/^(\d+)-(\d+)$/u', $compact, $m)) {
                $start = (int) $m[1]; $end = (int) $m[2];
                if ($end >= $start && ($end - $start) <= 500) {
                    for ($i = $start; $i <= $end; $i++) $tokens[] = (string) $i;
                    continue;
                }
            }
            if (preg_match_all('/\b\d+\b/u', $compact, $matches) && count($matches[0]) > 1) {
                foreach ($matches[0] as $number) $tokens[] = $number;
                continue;
            }
            if (preg_match('/^\d+$/u', $compact)) $tokens[] = $compact;
        }
        return array_values(array_unique($tokens));
    }

    private function equipmentField(string $label): ?string
    {
        $map = [
            'marka' => 'brand', 'model' => 'model', 'seri no' => 'serial_no', 'serino' => 'serial_no',
            'bulunduğu yer' => 'location', 'bulundugu yer' => 'location',
            'ölçülen basınç' => 'measured_pressure', 'olculen basinc' => 'measured_pressure',
            'hortum uzunluğu' => 'hose_length', 'hortum uzunlugu' => 'hose_length',
            'dolaplar arası mesafe' => 'distance_between', 'dolaplar arasi mesafe' => 'distance_between',
            'korunan alandan uzaklığı ( 5 - 15m )' => 'protected_area_distance', 'korunan alandan uzakligi ( 5 - 15m )' => 'protected_area_distance',
            'hidrantlar arası max. mesafe ( 150 - 125 - 100 - 50m )' => 'max_distance_between', 'hidrantlar arasi max. mesafe ( 150 - 125 - 100 - 50m )' => 'max_distance_between',
        ];
        return $map[$label] ?? null;
    }

    private function extractControls(array $tables, array $controlTemplates): array
    {
        $definitions = [];
        foreach ($controlTemplates as $control) {
            if (!is_array($control)) continue;
            foreach ((array) ($control['control_code_patterns'] ?? []) as $pattern) $definitions[(string) $pattern] = true;
        }
        $out = [];
        foreach ($tables as $table) {
            foreach ($this->matrix($table) as $row) {
                if (!$row) continue;
                $codes = [];
                foreach (array_slice($row, 0, 2) as $value) {
                    $value = trim((string) $value);
                    if (preg_match('/^([A-ZÇĞİÖŞÜ]+)\.?(\d+(?:\.\d+)*)\.?$/u', $value, $m)) $codes[] = rtrim($m[1] . '.' . $m[2], '.');
                }
                foreach ($codes as $code) {
                    $definition = false;
                    foreach (array_keys($definitions) as $pattern) if ($this->regexMatches($pattern, $code)) { $definition = true; break; }
                    if (!$definition) continue;
                    $result = null;
                    foreach (array_slice($row, 2) as $value) {
                        $status = $this->status($value);
                        if ($status !== null) { $result = $status; break; }
                    }
                    $out[$this->normalizeCode($code)] = [
                        'code' => $code,
                        'result' => $result,
                        'source_pages' => array_values(array_unique(array_filter([(int) ($table['page'] ?? 0)]))),
                    ];
                }
            }
        }
        return array_values($out);
    }

    private function extractReportInformation(array $camelot): array
    {
        $fields = [
            'report_no' => ['Rapor No'], 'inspection_date' => ['Muayene Tarihi ve Saati', 'Muayene Tarihi'],
            'report_date' => ['Rapor Tarihi'], 'next_inspection_date' => ['Gelecek Muayene Tarihi'],
            'equipment_serial_or_code' => ['Ekipman Seri No / Kod'], 'equipment_location' => ['Ekipmanın Bulunduğu Yer'],
            'company_title' => ['Ünvanı', 'Unvanı'], 'address' => ['Adresi'], 'inspection_address' => ['Muayene Adresi'],
            'contract_id' => ['Sözleşme ID'], 'sgk_registration_no' => ['SGK Sicil No'],
        ];
        $result = [];
        foreach ((array) ($camelot['tables'] ?? []) as $table) {
            if (!is_array($table) || ($table['flavor'] ?? '') !== 'stream') continue;
            foreach ($this->matrix($table) as $row) {
                for ($i = 0; $i < count($row); $i++) {
                    $label = trim((string) ($row[$i] ?? ''));
                    foreach ($fields as $key => $labels) if (in_array($label, $labels, true)) {
                        $value = $this->nextNonEmpty($row, $i + 1);
                        if ($value !== null) $result[$key] = $this->cleanValue($value);
                    }
                }
            }
        }
        return $result;
    }

    private function extractFacilityInformation(array $camelot): array
    {
        $result = [];
        foreach ((array) ($camelot['tables'] ?? []) as $table) {
            if (!is_array($table) || ($table['flavor'] ?? '') !== 'stream' || (int) ($table['page'] ?? 0) !== 1) continue;
            foreach ($this->matrix($table) as $row) {
                $text = trim(implode(' ', array_filter(array_map('strval', $row))));
                if (preg_match('/^4\.1\. YANGIN MEKANİK TESİSATI PROJE BİLGİLERİ$/iu', $text)) $result['section'] = '4.1. YANGIN MEKANİK TESİSATI PROJE BİLGİLERİ';
            }
        }
        return $result;
    }

    private function extractOverallResult(array $camelot): array
    {
        foreach ((array) ($camelot['tables'] ?? []) as $table) {
            if (!is_array($table) || ($table['flavor'] ?? '') !== 'stream' || (int) ($table['page'] ?? 0) !== 12) continue;
            $text = trim(implode(' ', array_filter(array_map('strval', (array) ($table['data'][0] ?? [])))));
            if ($text !== '') return ['raw' => $text];
        }
        return [];
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
        $result = @preg_match($pattern, $value);
        if ($result === 1) return true;
        return $this->normalizeLabel($pattern) === $this->normalizeLabel($value);
    }

    private function normalizeLabel(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return rtrim($value, ':');
    }

    private function normalizeCode(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? '', 'UTF-8');
    }

    private function status(mixed $value): ?string
    {
        $value = mb_strtoupper(trim((string) $value), 'UTF-8');
        $value = preg_replace('/[.\s_\-]+/u', '', $value) ?? $value;
        if ($value === '' || mb_strlen($value, 'UTF-8') > 20) return null;
        return in_array($value, ['U', 'UD', 'N'], true) ? $value : null;
    }

    private function nextNonEmpty(array $row, int $start): ?string
    {
        for ($i = $start, $count = count($row); $i < $count; $i++) {
            $value = trim((string) ($row[$i] ?? ''));
            if ($value !== '' && $value !== '-') return $value;
        }
        return null;
    }

    private function cleanValue(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function string(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;
        return $value === '' ? null : $value;
    }

    private function itemKey(array $template, string $code): string
    {
        $name = $this->string($template['equipment_name'] ?? null) ?? 'equipment';
        return $name . ':' . $code;
    }
}
