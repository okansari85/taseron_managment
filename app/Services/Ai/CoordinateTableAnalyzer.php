<?php

namespace App\Services\Ai;

/**
 * Deterministic PDF matrix mapper.
 *
 * Rules:
 * - Gemini supplies the control code/description/status for each system.
 * - This class only finds which known equipment codes are physically at that
 *   control row/column in the PDF matrix.
 * - Equipment may be horizontal or vertical; orientation is detected from
 *   the equipment-code geometry.
 * - Status values are not hard-coded; any short alphabetic cell value is read.
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

            $horizontalCount = count($this->bestBand($equipmentHits, 'y'));
            $verticalCount = count($this->bestBand($equipmentHits, 'x'));
            $pageNo = (int) ($page['page'] ?? $page['page_number'] ?? 0);

            if ($horizontalCount >= $verticalCount) {
                $equipmentAxis = $this->bestBand($equipmentHits, 'y');
                $this->sortAxis($equipmentAxis, 'x');
                $this->sortAxis($controlHits, 'y');
                $mapped = $this->mapHorizontal($words, $equipmentAxis, $controlHits);
            } else {
                $equipmentAxis = $this->bestBand($equipmentHits, 'x');
                $this->sortAxis($equipmentAxis, 'y');
                $this->sortAxis($controlHits, 'x');
                $mapped = $this->mapVertical($words, $equipmentAxis, $controlHits);
            }

            foreach ($mapped as $row) {
                foreach (($row['results'] ?? []) as $status => $refs) {
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
        return array_values(array_filter($words, fn($w) => is_array($w) && isset($w['text'], $w['x'], $w['y'])));
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

    private function controlCodes(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
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
                $hits[] = $this->hit($knownControls[$normalized], $word);
                continue;
            }
            if (!$knownControls && preg_match('/^\d+(?:\.\d+)+$/u', $raw)) {
                $hits[] = $this->hit($raw, $word);
            }
        }
        $unique = [];
        foreach ($hits as $hit) {
            $key = $this->normalizeControlCode($hit['code']) . '|' . round($hit['x'], 2) . '|' . round($hit['y'], 2);
            $unique[$key] = $hit;
        }
        return array_values($unique);
    }

    private function hit(string $code, array $word): array
    {
        return [
            'code' => $code,
            'x' => (float) $word['x'],
            'y' => (float) $word['y'],
            'width' => max(1.0, (float) ($word['width'] ?? 1)),
            'height' => max(1.0, (float) ($word['height'] ?? 1)),
        ];
    }

    private function bestBand(array $hits, string $axis): array
    {
        if (count($hits) <= 1) return $hits;
        $tolerance = $this->bandTolerance($hits, $axis);
        $best = [];
        foreach ($hits as $anchor) {
            $band = array_values(array_filter($hits, fn($hit) => abs((float) $hit[$axis] - (float) $anchor[$axis]) <= $tolerance));
            if (count($band) > count($best)) $best = $band;
        }
        $unique = [];
        foreach ($best ?: $hits as $item) $unique[$this->normalizeCode($item['code'])] = $item;
        return array_values($unique);
    }

    private function bandTolerance(array $hits, string $axis): float
    {
        $sizes = [];
        foreach ($hits as $hit) {
            $size = $axis === 'x' ? ($hit['width'] ?? 1) : ($hit['height'] ?? 1);
            if ($size > 0) $sizes[] = (float) $size;
        }
        if (!$sizes) return 8.0;
        sort($sizes);
        return max(5.0, min(15.0, $sizes[(int) floor(count($sizes) / 2)] * 1.5));
    }

    private function mapHorizontal(array $words, array $equipmentAxis, array $controls): array
    {
        $out = [];
        if (!$equipmentAxis) return $out;
        $this->sortAxis($equipmentAxis, 'x');
        $firstX = $this->center($equipmentAxis[0], 'x');
        $lastX = $this->center($equipmentAxis[count($equipmentAxis) - 1], 'x');
        $minX = min($firstX, $lastX);
        $maxX = max($firstX, $lastX);
        $rowTolerance = $this->rowTolerance($controls, 'y', $equipmentAxis);

        foreach ($controls as $control) {
            $cy = $this->center($control, 'y');
            $candidates = [];
            foreach ($words as $word) {
                $text = trim((string) ($word['text'] ?? ''));
                if ($text === '' || $this->normalizeControlCode($text) === $this->normalizeControlCode($control['code'])) continue;
                $x = $this->center($word, 'x');
                $y = $this->center($word, 'y');
                if ($x < $minX || $x > $maxX || abs($y - $cy) > $rowTolerance) continue;
                $status = $this->statusValue($text);
                if ($status === null) continue;
                $candidates[] = ['word' => $word, 'status' => $status];
            }

            $results = [];
            foreach ($candidates as $candidate) {
                $nearest = $this->nearestEquipment($candidate['word'], $equipmentAxis, 'x');
                if ($nearest === null) continue;
                $results[$candidate['status']][] = $nearest['code'];
            }
            foreach ($results as $status => $refs) $results[$status] = array_values(array_unique($refs));

            $out[] = [
                'code' => $control['code'],
                'description' => $this->descriptionHorizontal($words, $control, $minX),
                'results' => $results,
            ];
        }
        return $out;
    }

    private function mapVertical(array $words, array $equipmentAxis, array $controls): array
    {
        $out = [];
        if (!$equipmentAxis) return $out;
        $this->sortAxis($equipmentAxis, 'y');
        $firstY = $this->center($equipmentAxis[0], 'y');
        $lastY = $this->center($equipmentAxis[count($equipmentAxis) - 1], 'y');
        $minY = min($firstY, $lastY);
        $maxY = max($firstY, $lastY);
        $columnTolerance = $this->rowTolerance($controls, 'x', $equipmentAxis);

        foreach ($controls as $control) {
            $cx = $this->center($control, 'x');
            $candidates = [];
            foreach ($words as $word) {
                $text = trim((string) ($word['text'] ?? ''));
                if ($text === '' || $this->normalizeControlCode($text) === $this->normalizeControlCode($control['code'])) continue;
                $x = $this->center($word, 'x');
                $y = $this->center($word, 'y');
                if ($y < $minY || $y > $maxY || abs($x - $cx) > $columnTolerance) continue;
                $status = $this->statusValue($text);
                if ($status === null) continue;
                $candidates[] = ['word' => $word, 'status' => $status];
            }

            $results = [];
            foreach ($candidates as $candidate) {
                $nearest = $this->nearestEquipment($candidate['word'], $equipmentAxis, 'y');
                if ($nearest === null) continue;
                $results[$candidate['status']][] = $nearest['code'];
            }
            foreach ($results as $status => $refs) $results[$status] = array_values(array_unique($refs));

            $out[] = [
                'code' => $control['code'],
                'description' => $this->descriptionVertical($words, $control, $minY),
                'results' => $results,
            ];
        }
        return $out;
    }

    private function nearestEquipment(array $word, array $equipmentAxis, string $axis): ?array
    {
        $value = $this->center($word, $axis);
        $best = null;
        $distance = PHP_FLOAT_MAX;
        foreach ($equipmentAxis as $equipment) {
            $d = abs($value - $this->center($equipment, $axis));
            if ($d < $distance) {
                $distance = $d;
                $best = $equipment;
            }
        }
        return $best;
    }

    private function rowTolerance(array $controls, string $axis, array $equipmentAxis): float
    {
        $sizes = [];
        foreach ($controls as $control) {
            $size = $axis === 'x' ? ($control['width'] ?? 1) : ($control['height'] ?? 1);
            if ($size > 0) $sizes[] = (float) $size;
        }
        foreach ($equipmentAxis as $equipment) {
            $size = $axis === 'x' ? ($equipment['width'] ?? 1) : ($equipment['height'] ?? 1);
            if ($size > 0) $sizes[] = (float) $size;
        }
        if (!$sizes) return 12.0;
        sort($sizes);
        return max(8.0, min(18.0, $sizes[(int) floor(count($sizes) / 2)] * 2.0));
    }

    private function statusValue(string $text): ?string
    {
        $value = strtoupper(trim($text));
        $value = str_replace(['.', ' ', '_', '-'], '', $value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 6) return null;
        if (!preg_match('/^[A-ZÇĞİÖŞÜ]+$/u', $value)) return null;
        return $value;
    }

    private function descriptionHorizontal(array $words, array $control, float $equipmentStart): ?string
    {
        $parts = [];
        $cy = $this->center($control, 'y');
        foreach ($words as $word) {
            $text = trim((string) ($word['text'] ?? ''));
            if ($text === '' || $this->normalizeControlCode($text) === $this->normalizeControlCode($control['code'])) continue;
            if ($this->statusValue($text) !== null) continue;
            if ($this->center($word, 'x') >= $equipmentStart) continue;
            if (abs($this->center($word, 'y') - $cy) > 18.0) continue;
            $parts[] = ['x' => (float) $word['x'], 'text' => $text];
        }
        usort($parts, fn($a, $b) => $a['x'] <=> $b['x']);
        return $parts ? trim(implode(' ', array_column($parts, 'text'))) : null;
    }

    private function descriptionVertical(array $words, array $control, float $equipmentStart): ?string
    {
        $parts = [];
        $cx = $this->center($control, 'x');
        foreach ($words as $word) {
            $text = trim((string) ($word['text'] ?? ''));
            if ($text === '' || $this->normalizeControlCode($text) === $this->normalizeControlCode($control['code'])) continue;
            if ($this->statusValue($text) !== null) continue;
            if ($this->center($word, 'y') >= $equipmentStart) continue;
            if (abs($this->center($word, 'x') - $cx) > 18.0) continue;
            $parts[] = ['y' => (float) $word['y'], 'text' => $text];
        }
        usort($parts, fn($a, $b) => $a['y'] <=> $b['y']);
        return $parts ? trim(implode(' ', array_column($parts, 'text'))) : null;
    }

    private function center(array $item, string $axis): float
    {
        $sizeKey = $axis === 'x' ? 'width' : 'height';
        return (float) $item[$axis] + ((float) ($item[$sizeKey] ?? 1.0) / 2.0);
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
            $refs = [];
            foreach (($control['equipment_refs'] ?? []) as $ref) $refs[] = $ref;
            $refs = array_values(array_unique($refs));
            sort($refs);
            $key = ($control['code'] ?? '') . '|' . ($control['status'] ?? '') . '|' . implode(',', $refs);
            $control['equipment_refs'] = $refs;
            $out[$key] = $control;
        }
        return array_values($out);
    }
}
