<?php
namespace App\Services\Ai;

/**
 * Deterministic coordinate-based matrix analyzer.
 * Only resolves real U/UD/N cells against equipment columns. It never
 * invents relationships when the geometry is ambiguous.
 */
class CoordinateTableAnalyzer
{
    public function analyze(array $pages, array $equipment): array
    {
        $equipmentCodes = $this->equipmentCodes($equipment);
        if (!$equipmentCodes) return [];

        $results = [];
        foreach ($pages as $page) {
            if (!is_array($page)) continue;
            $words = $this->words($page);
            if (!$words) continue;

            $columns = $this->findEquipmentColumns($words, $equipmentCodes);
            if (count($columns) < 2) continue;

            foreach ($this->findControlRows($words) as $row) {
                $mapping = $this->mapStatusCells($this->cellsNearY($words, $row['y']), $columns);
                if (!$mapping['has_status']) continue;

                // A row is equipment-scoped only when an actual UD cell can be
                // deterministically assigned to one or more equipment columns.
                $status = $mapping['has_ud'] ? 'UD' : $mapping['row_status'];
                $refs = $mapping['ud_refs'];
                if ($status !== 'UD' || !$refs) continue;

                $results[] = [
                    'code' => $row['code'],
                    'description' => $row['description'],
                    'status' => 'UD',
                    'scope' => 'equipment',
                    'equipment_refs' => $refs,
                    'source_pages' => [(int)($page['page'] ?? $page['page_number'] ?? 0)],
                    'system_name' => null,
                ];
            }
        }

        return $this->dedupeControls($results);
    }

    private function words(array $page): array
    {
        $words = $page['words'] ?? $page['tokens'] ?? [];
        if (!is_array($words)) return [];
        return array_values(array_filter($words, fn($w) => is_array($w) && isset($w['text'], $w['x'], $w['y'])));
    }

    private function equipmentCodes(array $equipment): array
    {
        $out = [];
        foreach ($equipment as $item) {
            if (!is_array($item)) continue;
            $raw = trim((string)($item['code'] ?? ''));
            if ($raw !== '') $out[$this->normalizeCode($raw)] = $raw;
        }
        return $out;
    }

    private function findEquipmentColumns(array $words, array $equipmentCodes): array
    {
        $hits = [];
        foreach ($words as $word) {
            $key = $this->normalizeCode((string)$word['text']);
            if ($key === '' || !isset($equipmentCodes[$key])) continue;
            $hits[] = [
                'code' => $equipmentCodes[$key],
                'x' => (float)$word['x'],
                'y' => (float)$word['y'],
                'width' => max(1.0, (float)($word['width'] ?? 1)),
                'height' => max(1.0, (float)($word['height'] ?? 1)),
            ];
        }

        if (!$hits) return [];
        // Header is the y-band containing the greatest number of distinct codes.
        $bands = [];
        foreach ($hits as $hit) {
            $placed = false;
            foreach ($bands as &$band) {
                if (abs($band['y'] - $hit['y']) <= max(3.0, $hit['height'])) {
                    $band['items'][] = $hit;
                    $band['y'] = ($band['y'] + $hit['y']) / 2;
                    $placed = true;
                    break;
                }
            }
            unset($band);
            if (!$placed) $bands[] = ['y' => $hit['y'], 'items' => [$hit]];
        }
        usort($bands, fn($a,$b) => count($b['items']) <=> count($a['items']));
        $header = $bands[0]['items'] ?? [];
        usort($header, fn($a,$b) => $a['x'] <=> $b['x']);

        $unique = [];
        foreach ($header as $column) $unique[$this->normalizeCode($column['code'])] = $column;
        return array_values($unique);
    }

    private function findControlRows(array $words): array
    {
        $rows = [];
        foreach ($words as $word) {
            $code = trim((string)$word['text']);
            if (!preg_match('/^\d+(?:\.\d+)+$/u', $code)) continue;
            $y = (float)$word['y'];
            $cells = $this->cellsNearY($words, $y);
            $statuses = array_values(array_filter(array_map(fn($w) => $this->normalizeStatus((string)$w['text']), $cells)));
            if (!$statuses) continue;
            $hasUd = in_array('UD', $statuses, true);
            $status = $hasUd ? 'UD' : (in_array('N', $statuses, true) ? 'N' : 'U');
            $rows[] = [
                'code' => $code,
                'description' => $this->descriptionForRow($cells, $code),
                'status' => $status,
                'y' => $y,
            ];
        }
        return $rows;
    }

    private function cellsNearY(array $words, float $y): array
    {
        $tol = $this->yTolerance($words);
        return array_values(array_filter($words, fn($w) => abs((float)$w['y'] - $y) <= $tol));
    }

    private function mapStatusCells(array $cells, array $columns): array
    {
        $udRefs = [];
        $statuses = [];
        $hasStatus = false;
        $tolerance = $this->xTolerance($columns);

        foreach ($cells as $cell) {
            $status = $this->normalizeStatus((string)$cell['text']);
            if ($status === null) continue;
            $hasStatus = true;
            $statuses[] = $status;

            if ($status !== 'UD') continue;
            $left = (float)$cell['x'];
            $right = $left + max(1.0, (float)($cell['width'] ?? 1));
            $center = ($left + $right) / 2;

            $covered = [];
            foreach ($columns as $column) {
                $cx = (float)$column['x'];
                $cw = max(1.0, (float)($column['width'] ?? 1));
                $columnLeft = $cx - ($cw / 2);
                $columnRight = $cx + ($cw / 2);
                $overlap = min($right, $columnRight) - max($left, $columnLeft);
                if ($overlap > 0 || abs($center - $cx) <= $tolerance) $covered[] = $column;
            }

            // Do not guess if one UD cell is too far from every equipment column.
            if (!$covered) continue;
            foreach ($covered as $column) $udRefs[$this->normalizeCode($column['code'])] = $column['code'];
        }

        return [
            'has_status' => $hasStatus,
            'has_ud' => in_array('UD', $statuses, true),
            'row_status' => in_array('N', $statuses, true) ? 'N' : 'U',
            'ud_refs' => array_values($udRefs),
        ];
    }

    private function descriptionForRow(array $cells, string $code): ?string
    {
        $parts = [];
        foreach ($cells as $word) {
            $text = trim((string)$word['text']);
            if ($text === '' || $text === $code || $this->normalizeStatus($text) !== null) continue;
            $parts[] = ['x' => (float)$word['x'], 'text' => $text];
        }
        usort($parts, fn($a,$b) => $a['x'] <=> $b['x']);
        return $parts ? trim(implode(' ', array_column($parts, 'text'))) : null;
    }

    private function normalizeStatus(string $text): ?string
    {
        $value = strtoupper(trim($text));
        $value = str_replace(['.', ' ', '_', '-'], '', $value);
        return in_array($value, ['U','UD','N'], true) ? $value : null;
    }

    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/u', '', $code);
        return str_replace(['–','—','‑'], '-', $code);
    }

    private function xTolerance(array $columns): float
    {
        $xs = array_map(fn($c) => (float)$c['x'], $columns);
        sort($xs);
        $gaps = [];
        for ($i=1; $i<count($xs); $i++) if ($xs[$i] > $xs[$i-1]) $gaps[] = $xs[$i]-$xs[$i-1];
        if (!$gaps) return 12.0;
        sort($gaps);
        $median = $gaps[(int)floor(count($gaps)/2)];
        return max(4.0, min(18.0, $median * 0.35));
    }

    private function yTolerance(array $words): float
    {
        $heights = array_values(array_filter(array_map(fn($w) => (float)($w['height'] ?? 0), $words), fn($h) => $h > 0));
        if (!$heights) return 4.0;
        sort($heights);
        return max(3.0, min(8.0, $heights[(int)floor(count($heights)/2)] * 0.8));
    }

    private function dedupeControls(array $controls): array
    {
        $out = [];
        foreach ($controls as $control) {
            $refs = array_values(array_unique($control['equipment_refs'] ?? []));
            sort($refs);
            $key = ($control['code'] ?? '') . '|' . implode(',', $refs);
            $control['equipment_refs'] = $refs;
            $out[$key] = $control;
        }
        return array_values($out);
    }
}
