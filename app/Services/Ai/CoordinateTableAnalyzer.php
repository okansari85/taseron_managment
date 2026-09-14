<?php
namespace App\Services\Ai;

/**
 * Deterministic coordinate-based matrix analyzer.
 * Maps every observed U/UD/N cell to the real equipment column that contains it.
 * No status or equipment relationship is invented.
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
            if (!$columns) continue;

            foreach ($this->findControlRows($words) as $row) {
                $mapping = $this->mapStatusCells($this->cellsNearY($words, $row['y']), $columns);
                if (!$mapping['has_status']) continue;

                foreach ($mapping['refs_by_status'] as $status => $refs) {
                    if (!$refs) continue;
                    $results[] = [
                        'code' => $row['code'],
                        'description' => $row['description'],
                        'status' => $status,
                        'scope' => 'equipment',
                        'equipment_refs' => $refs,
                        'source_pages' => [(int)($page['page'] ?? $page['page_number'] ?? 0)],
                        'system_name' => null,
                    ];
                }
            }
        }

        return $this->dedupeControls($results);
    }

    private function words(array $page): array
    {
        $words = $page['words'] ?? $page['tokens'] ?? [];
        if (!is_array($words)) return [];
        return array_values(array_filter(
            $words,
            fn($w) => is_array($w) && isset($w['text'], $w['x'], $w['y'])
        ));
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

        $bands = [];
        foreach ($hits as $hit) {
            $placed = false;
            foreach ($bands as &$band) {
                if (abs($band['y'] - $hit['y']) <= max(3.0, $hit['height'] * 0.8)) {
                    $band['items'][] = $hit;
                    $band['y'] = ($band['y'] + $hit['y']) / 2;
                    $placed = true;
                    break;
                }
            }
            unset($band);
            if (!$placed) $bands[] = ['y' => $hit['y'], 'items' => [$hit]];
        }

        usort($bands, fn($a, $b) => count($b['items']) <=> count($a['items']));
        $header = $bands[0]['items'] ?? [];
        usort($header, fn($a, $b) => $a['x'] <=> $b['x']);

        $unique = [];
        foreach ($header as $column) {
            $key = $this->normalizeCode($column['code']);
            if (!isset($unique[$key])) $unique[$key] = $column;
        }
        return array_values($unique);
    }

    private function findControlRows(array $words): array
    {
        $rows = [];
        $seen = [];
        foreach ($words as $word) {
            $code = trim((string)$word['text']);
            if (!preg_match('/^\d+(?:\.\d+)+$/u', $code)) continue;

            $y = (float)$word['y'];
            $cells = $this->cellsNearY($words, $y);
            $statuses = array_values(array_filter(array_map(
                fn($w) => $this->normalizeStatus((string)$w['text']),
                $cells
            )));
            if (!$statuses) continue;

            $key = $code . '|' . round($y, 2);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $rows[] = [
                'code' => $code,
                'description' => $this->descriptionForRow($cells, $code),
                'y' => $y,
            ];
        }
        return $rows;
    }

    private function cellsNearY(array $words, float $y): array
    {
        $tol = $this->yTolerance($words);
        return array_values(array_filter(
            $words,
            fn($w) => abs((float)$w['y'] - $y) <= $tol
        ));
    }

    private function mapStatusCells(array $cells, array $columns): array
    {
        $refsByStatus = [
            'U' => [],
            'UD' => [],
            'N' => [],
        ];
        $hasStatus = false;

        usort($columns, fn($a, $b) => (float)$a['x'] <=> (float)$b['x']);
        $tolerance = $this->xTolerance($columns);

        foreach ($cells as $cell) {
            $status = $this->normalizeStatus((string)$cell['text']);
            if ($status === null) continue;
            $hasStatus = true;

            $left = (float)$cell['x'];
            $width = max(1.0, (float)($cell['width'] ?? 1));
            $center = $left + ($width / 2.0);

            // Prefer the equipment column whose center is closest to the status cell.
            $nearest = null;
            $nearestDistance = PHP_FLOAT_MAX;
            foreach ($columns as $column) {
                $distance = abs($center - (float)$column['x']);
                if ($distance < $nearestDistance) {
                    $nearestDistance = $distance;
                    $nearest = $column;
                }
            }

            if ($nearest === null) continue;

            // Do not attach a status from the description area to a distant column.
            // The threshold is based on the actual column spacing.
            if ($nearestDistance > max($tolerance * 3.0, $this->medianColumnGap($columns) * 0.60)) {
                continue;
            }

            // Wide status cells can represent a merged cell spanning several columns.
            $covered = [];
            if ($width > max(2.0, $tolerance * 1.5)) {
                $right = $left + $width;
                foreach ($columns as $column) {
                    $cx = (float)$column['x'];
                    if ($cx >= $left - $tolerance && $cx <= $right + $tolerance) {
                        $covered[] = $column;
                    }
                }
            }

            if (!$covered) $covered = [$nearest];

            foreach ($covered as $column) {
                $key = $this->normalizeCode($column['code']);
                $refsByStatus[$status][$key] = $column['code'];
            }
        }

        foreach ($refsByStatus as $status => $refs) {
            $refsByStatus[$status] = array_values($refs);
        }

        return [
            'has_status' => $hasStatus,
            'refs_by_status' => $refsByStatus,
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
        usort($parts, fn($a, $b) => $a['x'] <=> $b['x']);
        return $parts ? trim(implode(' ', array_column($parts, 'text'))) : null;
    }

    private function normalizeStatus(string $text): ?string
    {
        $value = strtoupper(trim($text));
        $value = str_replace(['.', ' ', '_', '-'], '', $value);
        return in_array($value, ['U', 'UD', 'N'], true) ? $value : null;
    }

    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/u', '', $code);
        return str_replace(['–', '—', '‑'], '-', $code);
    }

    private function medianColumnGap(array $columns): float
    {
        $xs = array_map(fn($c) => (float)$c['x'], $columns);
        sort($xs);
        $gaps = [];
        for ($i = 1; $i < count($xs); $i++) {
            if ($xs[$i] > $xs[$i - 1]) $gaps[] = $xs[$i] - $xs[$i - 1];
        }
        if (!$gaps) return 10.0;
        sort($gaps);
        return $gaps[(int)floor(count($gaps) / 2)];
    }

    private function xTolerance(array $columns): float
    {
        return max(3.0, min(12.0, $this->medianColumnGap($columns) * 0.30));
    }

    private function yTolerance(array $words): float
    {
        $heights = array_values(array_filter(
            array_map(fn($w) => (float)($w['height'] ?? 0), $words),
            fn($h) => $h > 0
        ));
        if (!$heights) return 4.0;
        sort($heights);
        return max(3.0, min(8.0, $heights[(int)floor(count($heights) / 2)] * 0.55));
    }

    private function dedupeControls(array $controls): array
    {
        $out = [];
        foreach ($controls as $control) {
            $refs = array_values(array_unique($control['equipment_refs'] ?? []));
            sort($refs);
            $key = ($control['code'] ?? '') . '|' . ($control['status'] ?? '') . '|' . implode(',', $refs);
            $control['equipment_refs'] = $refs;
            $out[$key] = $control;
        }
        return array_values($out);
    }
}
