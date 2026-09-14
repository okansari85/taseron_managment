<?php

namespace App\Services\Ai;

class CamelotFireSuppressionMerger
{
    public function merge(array $analysis, array $camelot): array
    {
        $tables = array_values(array_filter((array) ($camelot['tables'] ?? []), fn ($t) => is_array($t) && !empty($t['data'])));
        if (!$tables) return $analysis;

        foreach ((array) ($analysis['systems'] ?? []) as $si => $system) {
            if (!is_array($system)) continue;

            $components = (array) ($system['components'] ?? []);
            $equipmentCodes = $this->equipmentCodes($components);

            // Camelot is the deterministic source of equipment. Gemini/V12 may
            // already provide components, but it must never be a prerequisite.
            if (!$equipmentCodes) {
                foreach ($tables as $table) {
                    $matrix = $this->matrix($table);
                    if (!$matrix) continue;

                    $controlCodes = $this->controlCodes($system);
                    $header = $this->discoverEquipmentHeader($matrix, $controlCodes);
                    if (!$header) continue;

                    foreach ($header as $item) {
                        $components[] = [
                            'code' => $item['code'],
                            'name' => trim((string) ($system['name'] ?? '')) ?: null,
                            'location' => null,
                            'brand' => null,
                            'model' => null,
                            'serial_no' => null,
                            'properties' => [],
                            'source_pages' => array_values(array_filter([(int) ($table['page'] ?? 0)])),
                        ];
                    }

                    $equipmentCodes = $this->equipmentCodes($components);
                    if ($equipmentCodes) break;
                }
            }

            if (!$equipmentCodes) {
                $analysis['systems'][$si] = $system;
                continue;
            }

            $controlCodes = $this->controlCodes($system);

            foreach ($tables as $table) {
                $matrix = $this->matrix($table);
                if (!$matrix) continue;

                $header = $this->equipmentHeader($matrix, $equipmentCodes);
                if (!$header) continue;

                $this->mergeEquipmentRows($components, $header, $matrix);
                $this->mergeControlRows($system, $header, $matrix, $controlCodes, (int) ($table['page'] ?? 0));
            }

            $system['components'] = array_values($components);
            $system['equipment_count'] = count($components);
            $system['equipment_count_known'] = true;
            $analysis['systems'][$si] = $system;
        }

        $analysis['analyzer']['equipment_count'] = array_sum(array_map(
            fn ($system) => count((array) ($system['components'] ?? [])),
            (array) ($analysis['systems'] ?? [])
        ));

        return $analysis;
    }

    private function equipmentCodes(array $components): array
    {
        $codes = [];
        foreach ($components as $ci => $component) {
            $code = trim((string) ($component['code'] ?? ''));
            if ($code !== '') $codes[$this->key($code)] = ['index' => $ci, 'code' => $code];
        }
        return $codes;
    }

    private function controlCodes(array $system): array
    {
        $codes = [];
        foreach ((array) ($system['control_items'] ?? []) as $control) {
            $code = trim((string) ($control['code'] ?? ''));
            if ($code !== '') $codes[$this->controlKey($code)] = $code;
        }
        return $codes;
    }

    private function matrix(array $table): array
    {
        $data = (array) ($table['data'] ?? []);
        $out = [];
        foreach ($data as $ri => $row) {
            if (!is_array($row)) continue;
            $out[$ri] = array_map(fn ($v) => trim((string) $v), array_values($row));
        }
        return $out;
    }

    private function equipmentHeader(array $matrix, array $equipmentCodes): array
    {
        $best = [];
        foreach ($matrix as $row) {
            $hits = [];
            foreach ($row as $ci => $value) {
                $k = $this->key($value);
                if ($k !== '' && isset($equipmentCodes[$k])) {
                    $hits[$k] = ['column' => $ci, 'code' => $equipmentCodes[$k]['code']];
                }
            }
            if (count($hits) > count($best)) $best = $hits;
        }
        return count($best) >= 2 ? $best : [];
    }

    /**
     * Discover a physical equipment header from Camelot only when the same
     * matrix also contains known semantic control rows. This prevents report
     * headings, contact information, status words and unrelated values from
     * being mistaken for equipment identifiers.
     */
    private function discoverEquipmentHeader(array $matrix, array $controlCodes): array
    {
        $best = [];
        $bestScore = -1;

        foreach ($matrix as $rowIndex => $row) {
            $hits = [];
            $numericHits = 0;

            foreach ($row as $ci => $value) {
                $value = trim((string) $value);
                if (!$this->isEquipmentCodeCandidate($value)) continue;

                $k = $this->key($value);
                if ($k === '') continue;
                $hits[$k] = ['column' => $ci, 'code' => $value];
                if ($this->isNumericEquipmentCandidate($value)) $numericHits++;
            }

            // A physical equipment header needs multiple identifiers. Alpha-only
            // labels (e.g. a pump named "Jokey") are accepted only alongside
            // at least two numeric/alphanumeric identifiers in the same header.
            if (count($hits) < 2 || $numericHits < 2) continue;

            $controlRows = 0;
            $sample = min(count($matrix), $rowIndex + 1 + 80);
            for ($ri = $rowIndex + 1; $ri < $sample; $ri++) {
                $code = $this->extractControlCode($matrix[$ri][0] ?? '');
                if ($code !== '' && isset($controlCodes[$this->controlKey($code)])) {
                    $controlRows++;
                }
            }

            // Do not accept a candidate row merely because it has short strings.
            // It must be structurally tied to the semantic control matrix.
            if ($controlRows < 1) continue;

            $score = (count($hits) * 10) + min($controlRows, 10);
            if ($score > $bestScore) {
                $best = $hits;
                $bestScore = $score;
            }
        }

        return count($best) >= 2 ? array_values($best) : [];
    }

    private function isEquipmentCodeCandidate(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 20) return false;
        if ($this->extractControlCode($value) !== '') return false;

        $normalized = preg_replace('/[.\s_\-]+/u', '', mb_strtoupper($value, 'UTF-8')) ?? $value;
        if ($normalized !== '' && preg_match('/^[A-ZÇĞİÖŞÜ]+$/u', $normalized)) {
            return mb_strlen($normalized, 'UTF-8') <= 12;
        }

        return $this->isNumericEquipmentCandidate($value);
    }

    private function isNumericEquipmentCandidate(string $value): bool
    {
        return preg_match('/^[A-ZÇĞİÖŞÜ]*\d+[A-ZÇĞİÖŞÜ]*(?:[-\/]\d+[A-ZÇĞİÖŞÜ]*)*$/u', trim($value)) === 1;
    }

    private function mergeEquipmentRows(array &$components, array $header, array $matrix): void
    {
        $componentByCode = [];
        foreach ($components as $ci => $component) {
            $componentByCode[$this->key((string) ($component['code'] ?? ''))] = $ci;
        }

        $labels = [
            'kat' => 'location',
            'lokasyon' => 'location',
            'yer' => 'location',
            'marka' => 'brand',
            'model' => 'model',
            'seri no' => 'serial_no',
            'serino' => 'serial_no',
        ];

        foreach ($matrix as $row) {
            if (!$row) continue;
            $label = $this->normalizeLabel($row[0] ?? '');
            if (!isset($labels[$label])) continue;
            $field = $labels[$label];
            foreach ($header as $item) {
                $ci = $item['column'];
                if (!isset($row[$ci])) continue;
                $value = trim((string) $row[$ci]);
                if ($value === '' || $value === '-') continue;
                $componentIndex = $componentByCode[$this->key($item['code'])] ?? null;
                if ($componentIndex === null) continue;
                $components[$componentIndex][$field] = $value;
            }
        }
    }

    private function mergeControlRows(array &$system, array $header, array $matrix, array $controlCodes, int $page): void
    {
        foreach ($matrix as $row) {
            if (!$row) continue;
            $code = $this->extractControlCode($row[0] ?? '');
            if ($code === '' || !isset($controlCodes[$this->controlKey($code)])) continue;
            $canonical = $controlCodes[$this->controlKey($code)];
            $statusRefs = [];

            foreach ($header as $item) {
                $ci = $item['column'];
                $status = $this->status($row[$ci] ?? '');
                if ($status === null) continue;
                $statusRefs[$status][] = $item['code'];
            }

            foreach ($statusRefs as $status => $refs) {
                $this->addResult($system, $canonical, $status, $refs, $page);
            }
        }
    }

    private function addResult(array &$system, string $code, string $status, array $refs, int $page): void
    {
        foreach ((array) ($system['control_items'] ?? []) as $i => $item) {
            if ($this->controlKey((string) ($item['code'] ?? '')) !== $this->controlKey($code)) continue;
            $system['control_items'][$i]['results'][$status] = array_values(array_unique(array_merge(
                (array) ($system['control_items'][$i]['results'][$status] ?? []), $refs
            )));
            if ($page > 0) {
                $system['control_items'][$i]['source_pages'] = array_values(array_unique(array_merge(
                    (array) ($system['control_items'][$i]['source_pages'] ?? []), [$page]
                )));
            }
            return;
        }
    }

    /**
     * Control codes are report-defined. Do not assume the 5.xx format.
     */
    private function extractControlCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        if (preg_match('/^([A-ZÇĞİÖŞÜ]+\.)?\d+(?:\.\d+)*\.?\b/u', $value, $m)) {
            return rtrim($m[0], '.');
        }

        return '';
    }

    private function status(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;

        $value = mb_strtoupper($value, 'UTF-8');
        $value = preg_replace('/[.\s_\-]+/u', '', $value) ?? $value;

        if ($value === '' || mb_strlen($value, 'UTF-8') > 20) return null;
        if (!preg_match('/^[A-ZÇĞİÖŞÜ]+$/u', $value)) return null;

        return $value;
    }

    private function normalizeLabel(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return rtrim($value, ':');
    }

    private function controlKey(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        return rtrim($value, '.');
    }

    private function key(string $value): string
    {
        return strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? '');
    }
}
