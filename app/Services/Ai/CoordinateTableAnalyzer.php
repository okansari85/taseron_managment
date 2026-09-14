<?php

namespace App\Services\Ai;

/**
 * Deterministic PDF matrix mapper.
 *
 * The only things that matter are the geometry of the PDF and the known
 * equipment/control labels:
 *   - equipment labels form one axis
 *   - control labels form the other axis
 *   - the status text at their intersection belongs to that equipment/control
 *
 * The orientation is discovered from the PDF. Nothing assumes that equipment
 * is always X or that controls are always Y, and status values are dynamic.
 */
class CoordinateTableAnalyzer
{
    public function analyze(array $pages, array $equipment, array $controlCodes = []): array
    {
        $equipmentCodes = $this->equipmentCodes($equipment);
        if (!$equipmentCodes) return [];

        $knownControls = $this->controlCodes($controlCodes);
        $results = [];

        foreach ($pages as $page) {
            if (!is_array($page)) continue;
            $words = $this->words($page);
            if (!$words) continue;

            $equipmentHits = $this->findEquipmentHits($words, $equipmentCodes);
            if (!$equipmentHits) continue;

            $controlHits = $this->findControlHits($words, $knownControls);
            if (!$controlHits) continue;

            $orientation = $this->detectOrientation($equipmentHits);

            if ($orientation === 'horizontal_equipment') {
                $equipmentAxis = $this->bestBand($equipmentHits, 'y');
                $this->sortAxis($equipmentAxis, 'x');
                $controlAxis = $this->uniqueControlItems($controlHits);
                $this->sortAxis($controlAxis, 'y');
                $mapped = $this->mapHorizontal($words, $equipmentAxis, $controlAxis);
            } else {
                $equipmentAxis = $this->bestBand($equipmentHits, 'x');
                $this->sortAxis($equipmentAxis, 'y');
                $controlAxis = $this->uniqueControlItems($controlHits);
                $this->sortAxis($controlAxis, 'x');
                $mapped = $this->mapVertical($words, $equipmentAxis, $controlAxis);
            }

            $pageNo = (int) ($page['page'] ?? $page['page_number'] ?? 0);
            foreach ($mapped as $row) {
                foreach ($row['results'] as $status => $refs) {
                    if (!$refs) continue;
                    $results[] = [
                        'code' => $row['code'],
                        'description' => $row['description'],
                        'status' => $status,
                        'scope' => 'equipment',
                        'equipment_refs' => array_values(array_unique($refs)),
                        'source_pages' => [$pageNo],
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
            $raw = trim((string) ($item['code'] ?? ''));
            if ($raw !== '') $out[$this->normalizeCode($raw)] = $raw;
        }
        return $out;
    }

    private function controlCodes(array $controlCodes): array
    {
        $out = [];
        foreach ($controlCodes as $code) {
            if (is_array($code)) $code = $code['code'] ?? '';
            $raw = trim((string) $code);
            if ($raw !== '') $out[$this->normalizeControlCode($raw)] = $raw;
        }
        return $out;
    }

    private function findEquipmentHits(array $words, array $equipmentCodes): array
    {
        $hits = [];
        foreach ($words as $word) {
            $key = $this->normalizeCode((string) $word['text']);
            if ($key === '' || !isset($equipmentCodes[$key])) continue;
            $hits[] = [
                'code' => $equipmentCodes[$key],
                'x' => (float) $word['x'],
                'y' => (float) $word['y'],
                'width' => max(1.0, (float) ($word['width'] ?? 1)),
                'height' => max(1.0, (float) ($word['height'] ?? 1)),
            ];
        }
        return $hits;
    }

    private function findControlHits(array $words, array $knownControls): array
    {
        $hits = [];
        foreach ($words as $word) {
            $raw = trim((string) $word['text']);
            if ($raw === '') continue;

            $normalized = $this->normalizeControlCode($raw);
            if ($normalized !== '' && isset($knownControls[$normalized])) {
                $hits[] = [
                    'code' => $knownControls[$normalized],
                    'x' => (float) $word['x'],
                    'y' => (float) $word['y'],
                    'width' => max(1.0, (float) ($word['width'] ?? 1)),
                    'height' => max(1.0, (float) ($word['height'] ?? 1)),
                ];
                continue;
            }

            if (!$knownControls && preg_match('/^\d+(?:\.\d+)+$/u', $raw)) {
                $hits[] = [
                    'code' => $raw,
                    'x' => (float) $word['x'],
                    'y' => (float) $word['y'],
                    'width' => max(1.0, (float) ($word['width'] ?? 1)),
                    'height' => max(1.0, (float) ($word['height'] ?? 1)),
                ];
            }
        }

        $unique = [];
        foreach ($hits as $hit) {
            $key = $this->normalizeControlCode($hit['code']) . '|' . round($hit['x'], 2) . '|' . round($hit['y'], 2);
            $unique[$key] = $hit;
        }
        return array_values($unique);
    }

    /** Equipment geometry alone determines which axis is the equipment axis. */
    private function detectOrientation(array $equipmentHits): string
    {
        $horizontal = count($this->bestBand($equipmentHits, 'y'));
        $vertical = count($this->bestBand($equipmentHits, 'x'));
        return $horizontal >= $vertical ? 'horizontal_equipment' : 'vertical_equipment';
    }

    private function bestBand(array $hits, string $axis): array
    {
        if (count($hits) <= 1) return $hits;
        $tolerance = $this->coordinateTolerance($hits);
        $best = [];

        foreach ($hits as $anchor) {
            $band = array_values(array_filter(
                $hits,
                fn($hit) => abs((float) $hit[$axis] - (float) $anchor[$axis]) <= $tolerance
            ));
            if (count($band) > count($best)) $best = $band;
        }

        $unique = [];
        foreach ($best ?: $hits as $item) {
            $key = $this->normalizeCode($item['code']);
            if (!isset($unique[$key])) $unique[$key] = $item;
        }
        return array_values($unique);
    }

    private function uniqueControlItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $key = $this->normalizeControlCode($item['code']) . '|' . round((float) $item['x'], 2) . '|' . round((float) $item['y'], 2);
            $out[$key] = $item;
        }
        return array_values($out);
    }

    private function mapHorizontal(array $words, array $equipmentAxis, array $controlAxis): array
    {
        if (!$equipmentAxis || !$controlAxis) return [];

        $this->sortAxis($equipmentAxis, 'x');
        $rowTolerance = $this->axisTolerance($controlAxis, 'y');
        $boundaries = $this->cellBoundaries($equipmentAxis, 'x');
        $results = [];

        foreach ($controlAxis as $control) {
            $rowY = (float) $control['y'];
            $rowWords = array_values(array_filter(
                $words,
                fn($word) => abs((float) $word['y'] - $rowY) <= $rowTolerance
            ));

            $statusWords = $this->matrixStatusWords($rowWords, $equipmentAxis, 'x');
            $mapped = [];

            foreach ($statusWords as $word) {
                $center = $this->center($word, 'x');
                $index = $this->findCellIndex($center, $boundaries);
                if ($index === null || !isset($equipmentAxis[$index])) continue;

                $status = $this->statusValue((string) $word['text']);
                if ($status === null) continue;
                $mapped[$index][$status][] = $equipmentAxis[$index]['code'];
            }

            $mapped = $this->resolveMultipleStatusWords($mapped, $equipmentAxis, 'x');
            $statusResults = [];
            foreach ($mapped as $cell) {
                foreach ($cell as $status => $refs) {
                    foreach ($refs as $ref) $statusResults[$status][] = $ref;
                }
            }

            foreach ($statusResults as $status => $refs) $statusResults[$status] = array_values(array_unique($refs));

            $results[] = [
                'code' => $control['code'],
                'description' => $this->descriptionHorizontal($rowWords, $control, $equipmentAxis),
                'results' => $statusResults,
            ];
        }

        return $results;
    }

    private function mapVertical(array $words, array $equipmentAxis, array $controlAxis): array
    {
        if (!$equipmentAxis || !$controlAxis) return [];

        $this->sortAxis($equipmentAxis, 'y');
        $columnTolerance = $this->axisTolerance($controlAxis, 'x');
        $boundaries = $this->cellBoundaries($equipmentAxis, 'y');
        $results = [];

        foreach ($controlAxis as $control) {
            $controlX = (float) $control['x'];
            $columnWords = array_values(array_filter(
                $words,
                fn($word) => abs((float) $word['x'] - $controlX) <= $columnTolerance
            ));

            $statusWords = $this->matrixStatusWords($columnWords, $equipmentAxis, 'y');
            $mapped = [];

            foreach ($statusWords as $word) {
                $center = $this->center($word, 'y');
                $index = $this->findCellIndex($center, $boundaries);
                if ($index === null || !isset($equipmentAxis[$index])) continue;

                $status = $this->statusValue((string) $word['text']);
                if ($status === null) continue;
                $mapped[$index][$status][] = $equipmentAxis[$index]['code'];
            }

            $mapped = $this->resolveMultipleStatusWords($mapped, $equipmentAxis, 'y');
            $statusResults = [];
            foreach ($mapped as $cell) {
                foreach ($cell as $status => $refs) {
                    foreach ($refs as $ref) $statusResults[$status][] = $ref;
                }
            }

            foreach ($statusResults as $status => $refs) $statusResults[$status] = array_values(array_unique($refs));

            $results[] = [
                'code' => $control['code'],
                'description' => $this->descriptionVertical($columnWords, $control, $equipmentAxis),
                'results' => $statusResults,
            ];
        }

        return $results;
    }

    /** Find short status-like cell text inside the equipment span; status values are dynamic. */
    private function matrixStatusWords(array $words, array $equipmentAxis, string $axis): array
    {
        if (!$equipmentAxis) return [];

        $first = $this->center($equipmentAxis[0], $axis);
        $last = $this->center($equipmentAxis[count($equipmentAxis) - 1], $axis);
        $min = min($first, $last);
        $max = max($first, $last);

        $out = [];
        foreach ($words as $word) {
            $text = trim((string) ($word['text'] ?? ''));
            if ($text === '') continue;

            $center = $this->center($word, $axis);
            if ($center < $min || $center > $max) continue;

            if ($this->statusValue($text) === null) continue;
            $out[] = $word;
        }
        return $out;
    }

    /** Any short alphabetic cell value can be a status; no U/UD/N whitelist. */
    private function statusValue(string $text): ?string
    {
        $value = strtoupper(trim($text));
        $value = str_replace(['.', ' ', '_', '-'], '', $value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 6) return null;
        if (!preg_match('/^[A-ZÇĞİÖŞÜ]+$/u', $value)) return null;
        return $value;
    }

    private function cellBoundaries(array $axisItems, string $axis): array
    {
        $centers = array_map(fn($item) => $this->center($item, $axis), $axisItems);
        $boundaries = [];
        for ($i = 0, $n = count($centers); $i < $n; $i++) {
            $left = $i === 0 ? -INF : (($centers[$i - 1] + $centers[$i]) / 2.0);
            $right = $i === $n - 1 ? INF : (($centers[$i] + $centers[$i + 1]) / 2.0);
            $boundaries[] = [$left, $right];
        }
        return $boundaries;
    }

    private function findCellIndex(float $value, array $boundaries): ?int
    {
        foreach ($boundaries as $i => [$min, $max]) {
            if ($value >= $min && $value < $max) return $i;
        }
        return null;
    }

    private function resolveMultipleStatusWords(array $mapped, array $axisItems, string $axis): array
    {
        foreach ($mapped as $index => $statuses) {
            if (count($statuses) <= 1) continue;
            $bestStatus = null;
            $bestDistance = PHP_FLOAT_MAX;
            $target = $this->center($axisItems[$index], $axis);
            foreach ($statuses as $status => $refs) {
                foreach ($refs as $ref) {
                    foreach ($axisItems as $candidate) {
                        if ($candidate['code'] !== $ref) continue;
                        $distance = abs($this->center($candidate, $axis) - $target);
                        if ($distance < $bestDistance) {
                            $bestDistance = $distance;
                            $bestStatus = $status;
                        }
                    }
                }
            }
            if ($bestStatus !== null) $mapped[$index] = [$bestStatus => [$axisItems[$index]['code']]];
        }
        return $mapped;
    }

    private function descriptionHorizontal(array $rowWords, array $control, array $equipmentAxis): ?string
    {
        $first = $this->center($equipmentAxis[0], 'x');
        $parts = [];
        foreach ($rowWords as $word) {
            $text = trim((string) $word['text']);
            if ($text === '' || $this->normalizeControlCode($text) === $this->normalizeControlCode($control['code'])) continue;
            if ($this->statusValue($text) !== null) continue;
            if ($this->center($word, 'x') >= $first) continue;
            $parts[] = ['x' => (float) $word['x'], 'text' => $text];
        }
        usort($parts, fn($a, $b) => $a['x'] <=> $b['x']);
        return $parts ? trim(implode(' ', array_column($parts, 'text'))) : null;
    }

    private function descriptionVertical(array $columnWords, array $control, array $equipmentAxis): ?string
    {
        $first = $this->center($equipmentAxis[0], 'y');
        $parts = [];
        foreach ($columnWords as $word) {
            $text = trim((string) $word['text']);
            if ($text === '' || $this->normalizeControlCode($text) === $this->normalizeControlCode($control['code'])) continue;
            if ($this->statusValue($text) !== null) continue;
            if ($this->center($word, 'y') >= $first) continue;
            $parts[] = ['y' => (float) $word['y'], 'text' => $text];
        }
        usort($parts, fn($a, $b) => $a['y'] <=> $b['y']);
        return $parts ? trim(implode(' ', array_column($parts, 'text'))) : null;
    }

    private function axisTolerance(array $items, string $axis): float
    {
        if (count($items) <= 1) return 6.0;
        $values = array_map(fn($item) => (float) $item[$axis], $items);
        sort($values);
        $gaps = [];
        for ($i = 1; $i < count($values); $i++) {
            if ($values[$i] > $values[$i - 1]) $gaps[] = $values[$i] - $values[$i - 1];
        }
        if (!$gaps) return 6.0;
        sort($gaps);
        return max(3.0, min(10.0, $gaps[(int) floor(count($gaps) / 2)] * 0.35));
    }

    private function coordinateTolerance(array $hits): float
    {
        $heights = array_values(array_filter(
            array_map(fn($h) => (float) ($h['height'] ?? 0), $hits),
            fn($v) => $v > 0
        ));
        if (!$heights) return 5.0;
        sort($heights);
        return max(3.0, min(10.0, $heights[(int) floor(count($heights) / 2)] * 0.8));
    }

    private function center(array $item, string $axis): float
    {
        return (float) $item[$axis] + ((float) ($item[$axis === 'x' ? 'width' : 'height'] ?? 1.0) / 2.0);
    }

    private function sortAxis(array &$items, string $axis): void
    {
        usort($items, fn($a, $b) => (float) $a[$axis] <=> (float) $b[$axis]);
    }

    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/u', '', $code);
        return str_replace(['–', '—', '‑', '−', '_'], '-', $code);
    }

    private function normalizeControlCode(string $code): string
    {
        return preg_replace('/\s+/u', '', trim($code));
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
