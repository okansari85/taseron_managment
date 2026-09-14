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
            $equipmentCodes = [];
            foreach ($components as $ci => $component) {
                $code = trim((string) ($component['code'] ?? ''));
                if ($code !== '') $equipmentCodes[$this->key($code)] = ['index' => $ci, 'code' => $code];
            }
            if (!$equipmentCodes) continue;

            $controlCodes = [];
            foreach ((array) ($system['control_items'] ?? []) as $control) {
                $code = trim((string) ($control['code'] ?? ''));
                if ($code !== '') $controlCodes[$this->controlKey($code)] = $code;
            }

            foreach ($tables as $table) {
                $matrix = $this->matrix($table);
                if (!$matrix) continue;
                $header = $this->equipmentHeader($matrix, $equipmentCodes);
                if (!$header) continue;

                $this->mergeEquipmentRows($components, $header, $matrix);
                $this->mergeControlRows($system, $header, $matrix, $controlCodes);
            }

            $system['components'] = array_values($components);
            $system['equipment_count'] = count($components);
            $analysis['systems'][$si] = $system;
        }

        return $analysis;
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
        foreach ($matrix as $ri => $row) {
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
            'seri no.' => 'serial_no',
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

    private function mergeControlRows(array &$system, array $header, array $matrix, array $controlCodes): void
    {
        foreach ($matrix as $ri => $row) {
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

            if (!$statusRefs) continue;
            foreach ($statusRefs as $status => $refs) {
                $this->addResult($system, $canonical, $status, $refs, (int) ($system['_camelot_page'] ?? 0));
            }
            $page = (int) ($this->tablePageForCurrent($matrix, $system) ?? 0);
            unset($page);
        }
    }

    private function addResult(array &$system, string $code, string $status, array $refs, int $unusedPage): void
    {
        foreach ((array) ($system['control_items'] ?? []) as $i => $item) {
            if ($this->controlKey((string) ($item['code'] ?? '')) !== $this->controlKey($code)) continue;
            $system['control_items'][$i]['results'][$status] = array_values(array_unique(array_merge(
                (array) ($system['control_items'][$i]['results'][$status] ?? []),
                $refs
            )));
            return;
        }
    }

    private function tablePageForCurrent(array $matrix, array $system): ?int
    {
        return null;
    }

    private function extractControlCode(string $value): string
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)+)\b/u', $value, $m)) return $m[1];
        return '';
    }

    private function status(string $value): ?string
    {
        $value = strtoupper(trim($value));
        $value = str_replace(['.', ' ', '_', '-'], '', $value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 6) return null;
        return preg_match('/^[A-ZÇĞİÖŞÜ]+$/u', $value) ? $value : null;
    }

    private function normalizeLabel(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return rtrim($value, ':');
    }

    private function controlKey(string $value): string
    {
        return strtoupper(str_replace([' ', 'U.D'], ['', 'UD'], trim($value)));
    }

    private function key(string $value): string
    {
        return strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? '');
    }
}
