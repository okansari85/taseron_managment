<?php
namespace App\Services\Ai;

/**
 * Deterministic coordinate-based table analyzer.
 *
 * Input is page-level word geometry. It detects equipment-code columns and
 * status cells (U/UD/N) by X/Y proximity. It intentionally returns only
 * semantic table relationships; raw coordinates never reach the final JSON.
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

            $rows = $this->findControlRows($words);
            foreach ($rows as $row) {
                $cells = $this->cellsNearY($words, $row['y']);
                $refs = $this->matchStatusCellsToColumns($cells, $columns);
                if (!$refs) continue;

                $results[] = [
                    'code' => $row['code'],
                    'description' => $row['description'],
                    'status' => $row['status'],
                    'scope' => 'equipment',
                    'equipment_refs' => $refs,
                    'source_pages' => [$page['page'] ?? $page['page_number'] ?? 0],
                    'system_name' => $row['system_name'] ?? null,
                ];
            }
        }

        return $this->dedupeControls($results);
    }

    private function words(array $page): array
    {
        $words = $page['words'] ?? $page['tokens'] ?? [];
        if (!is_array($words)) return [];
        return array_values(array_filter($words, function ($word) {
            return is_array($word) && isset($word['text']) && isset($word['x']) && isset($word['y']);
        }));
    }

    private function equipmentCodes(array $equipment): array
    {
        $out = [];
        foreach ($equipment as $item) {
            if (!is_array($item)) continue;
            $raw = trim((string)($item['code'] ?? ''));
            if ($raw === '') continue;
            $out[$this->normalizeCode($raw)] = $raw;
        }
        return $out;
    }

    private function findEquipmentColumns(array $words, array $equipmentCodes): array
    {
        $columns = [];
        foreach ($words as $word) {
            $raw = trim((string)$word['text']);
            $key = $this->normalizeCode($raw);
            if ($key !== '' && isset($equipmentCodes[$key])) {
                $columns[] = [
                    'code' => $equipmentCodes[$key],
                    'x' => (float)$word['x'],
                    'y' => (float)$word['y'],
                    'width' => (float)($word['width'] ?? 0),
                    'height' => (float)($word['height'] ?? 0),
                ];
            }
        }
        usort($columns, fn($a, $b) => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);
        return $this->selectHeaderColumns($columns);
    }

    private function selectHeaderColumns(array $columns): array
    {
        if (!$columns) return [];
        $byY = [];
        foreach ($columns as $column) $byY[(string)round($column['y'], 1)][] = $column;
        uasort($byY, fn($a, $b) => count($b) <=> count($a));
        $header = reset($byY) ?: [];
        usort($header, fn($a, $b) => $a['x'] <=> $b['x']);
        $unique = [];
        foreach ($header as $column) $unique[$this->normalizeCode($column['code'])] = $column;
        return array_values($unique);
    }

    private function findControlRows(array $words): array
    {
        $rows = [];
        foreach ($words as $word) {
            $text = trim((string)$word['text']);
            if (!preg_match('/^(\d+(?:\.\d+)+|[A-ZÇĞİÖŞÜ]\.?\d+(?:\.\d+)*)$/u', $text, $m)) continue;
            $y = (float)$word['y'];
            $status = $this->statusForRow($words, $y);
            if ($status === null) continue;
            $rows[] = [
                'code' => $text,
                'description' => $this->descriptionForRow($words, $y, $text),
                'status' => $status,
                'y' => $y,
                'system_name' => null,
            ];
        }
        return $rows;
    }

    private function statusForRow(array $words, float $y): ?string
    {
        foreach ($words as $word) {
            if (abs((float)$word['y'] - $y) > $this->yTolerance($words)) continue;
            $status = $this->normalizeStatus((string)$word['text']);
            if ($status !== null) return $status;
        }
        return null;
    }

    private function cellsNearY(array $words, float $y): array
    {
        $tol = $this->yTolerance($words);
        return array_values(array_filter($words, fn($word) => abs((float)$word['y'] - $y) <= $tol));
    }

    private function matchStatusCellsToColumns(array $cells, array $columns): array
    {
        $refs = [];
        foreach ($cells as $cell) {
            $status = $this->normalizeStatus((string)$cell['text']);
            if ($status === null) continue;
            $x = (float)$cell['x'];
            $nearest = null;
            $distance = INF;
            foreach ($columns as $column) {
                $d = abs($x - $column['x']);
                if ($d < $distance) {
                    $distance = $d;
                    $nearest = $column;
                }
            }
            if ($nearest === null) continue;
            if ($distance <= $this->xTolerance($columns)) {
                $refs[$this->normalizeCode($nearest['code'])] = $nearest['code'];
            }
        }
        return array_values($refs);
    }

    private function descriptionForRow(array $words, float $y, string $code): ?string
    {
        $tol = $this->yTolerance($words);
        $parts = [];
        foreach ($words as $word) {
            if (abs((float)$word['y'] - $y) > $tol) continue;
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

    private function xTolerance(array $columns): float
    {
        $xs = array_map(fn($c) => (float)$c['x'], $columns);
        sort($xs);
        $gaps = [];
        for ($i = 1; $i < count($xs); $i++) if (($xs[$i] - $xs[$i - 1]) > 0) $gaps[] = $xs[$i] - $xs[$i - 1];
        if (!$gaps) return 12.0;
        sort($gaps);
        $median = $gaps[(int)floor(count($gaps) / 2)];
        return max(4.0, min(18.0, $median * 0.35));
    }

    private function yTolerance(array $words): float
    {
        $heights = array_values(array_filter(array_map(fn($w) => (float)($w['height'] ?? 0), $words), fn($h) => $h > 0));
        if (!$heights) return 4.0;
        sort($heights);
        $median = $heights[(int)floor(count($heights) / 2)];
        return max(3.0, min(8.0, $median * 0.8));
    }

    private function dedupeControls(array $controls): array
    {
        $out = [];
        foreach ($controls as $control) {
            $refs = array_values(array_unique($control['equipment_refs'] ?? []));
            sort($refs);
            $key = ($control['code'] ?? '') . '|' . implode(',', $refs) . '|' . ($control['status'] ?? '');
            $control['equipment_refs'] = $refs;
            $out[$key] = $control;
        }
        return array_values($out);
    }
}
