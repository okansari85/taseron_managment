<?php

namespace App\Services\Ai;

/**
 * Deterministic coordinate-based matrix analyzer.
 *
 * The matrix orientation is discovered from the PDF itself:
 * - equipment can be the X axis and controls the Y axis
 * - OR equipment can be the Y axis and controls the X axis
 *
 * No fixed "first column", "first row" or fixed control-code format is
 * assumed when the known control codes are supplied by the caller.
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

            $orientation = $this->detectOrientation($equipmentHits, $controlHits);

            if ($orientation === 'horizontal_equipment') {
                $equipmentAxis = $this->equipmentAxisHorizontal($equipmentHits);
                $controlAxis = $this->controlAxisVertical($controlHits);
                if (!$equipmentAxis || !$controlAxis) continue;
                $mapped = $this->mapHorizontalEquipment($words, $equipmentAxis, $controlAxis);
            } else {
                $equipmentAxis = $this->equipmentAxisVertical($equipmentHits);
                $controlAxis = $this->controlAxisHorizontal($controlHits);
                if (!$equipmentAxis || !$controlAxis) continue;
                $mapped = $this->mapVerticalEquipment($words, $equipmentAxis, $controlAxis);
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
                        'equipment_refs' => $refs,
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

            // Fallback only when the caller does not know the control codes.
            // This keeps old numeric reports working, without making numeric
            // control codes a requirement for the dynamic matrix algorithm.
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

    private function detectOrientation(array $equipmentHits, array $controlHits): string
    {
        $equipmentHorizontal = $this->bandConcentration($equipmentHits, 'y');
        $equipmentVertical = $this->bandConcentration($equipmentHits, 'x');
        $controlHorizontal = $this->bandConcentration($controlHits, 'y');
        $controlVertical = $this->bandConcentration($controlHits, 'x');

        $normalScore = $equipmentHorizontal + $controlVertical;
        $transposeScore = $equipmentVertical + $controlHorizontal;

        if ($transposeScore > $normalScore) return 'vertical_equipment';
        return 'horizontal_equipment';
    }

    private function bandConcentration(array $hits, string $axis): float
    {
        if (count($hits) <= 1) return 1.0;

        $values = array_map(fn($h) => (float) $h[$axis], $hits);
        sort($values);
        $spread = max($values) - min($values);
        if ($spread <= 0.001) return 1.0;

        $otherAxis = $axis === 'x' ? 'y' : 'x';
        $otherValues = array_map(fn($h) => (float) $h[$otherAxis], $hits);
        sort($otherValues);
        $otherSpread = max($otherValues) - min($otherValues);
        if ($otherSpread <= 0.001) return 1.0;

        // We want many items sharing the same value on the requested axis.
        $tolerance = $this->coordinateTolerance($hits);
        $best = 1;
        foreach ($values as $value) {
            $count = 0;
            foreach ($values as $candidate) {
                if (abs($candidate - $value) <= $tolerance) $count++;
            }
            $best = max($best, $count);
        }

        return $best / count($hits);
    }

    private function equipmentAxisHorizontal(array $hits): array
    {
        $band = $this->bestBand($hits, 'y');
        usort($band, fn($a, $b) => $a['x'] <=> $b['x']);
        return $this->uniqueAxisItems($band);
    }

    private function equipmentAxisVertical(array $hits): array
    {
        $band = $this->bestBand($hits, 'x');
        usort($band, fn($a, $b) => $a['y'] <=> $b['y']);
        return $this->uniqueAxisItems($band);
    }

    private function controlAxisVertical(array $hits): array
    {
        $banded = $this->uniqueControlItems($hits);
        usort($banded, fn($a, $b) => $a['y'] <=> $b['y']);
        return $banded;
    }

    private function controlAxisHorizontal(array $hits): array
    {
        $banded = $this->uniqueControlItems($hits);
        usort($banded, fn($a, $b) => $a['x'] <=> $b['x']);
        return $banded;
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

        return $best ?: $hits;
    }

    private function uniqueAxisItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $key = $this->normalizeCode($item['code']);
            if (!isset($out[$key])) $out[$key] = $item;
        }
        return array_values($out);
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

    private function mapHorizontalEquipment(array $words, array $equipmentAxis, array $controlAxis): array
    {
        $results = [];
        $columnGap = $this->medianGap($equipmentAxis, 'x');
        $maxXDistance = max(8.0, $columnGap * 0.48);
        $rowTolerance = $this->axisRowTolerance($controlAxis, 'y');

        foreach ($controlAxis as $control) {
            $rowY = (float) $control['y'];
            $rowWords = array_values(array_filter(
                $words,
                fn($word) => abs((float) $word['y'] - $rowY) <= $rowTolerance
            ));

            $statusByEquipment = ['U' => [], 'UD' => [], 'N' => []];
            foreach ($equipmentAxis as $column) {
                $x = (float) $column['x'] + ((float) ($column['width'] ?? 1.0) / 2.0);
                $status = $this->nearestStatus($rowWords, $x, 'x', $maxXDistance);
                if ($status !== null) $statusByEquipment[$status][] = $column['code'];
            }

            $results[] = [
                'code' => $control['code'],
                'description' => $this->descriptionForHorizontalControl($words, $control, $rowTolerance),
                'results' => $this->cleanStatusResults($statusByEquipment),
            ];
        }

        return $results;
    }

    private function mapVerticalEquipment(array $words, array $equipmentAxis, array $controlAxis): array
    {
        $results = [];
        $rowGap = $this->medianGap($equipmentAxis, 'y');
        $maxYDistance = max(8.0, $rowGap * 0.48);
        $columnTolerance = $this->axisRowTolerance($controlAxis, 'x');

        foreach ($controlAxis as $control) {
            $controlX = (float) $control['x'];
            $columnWords = array_values(array_filter(
                $words,
                fn($word) => abs((float) $word['x'] - $controlX) <= $columnTolerance
            ));

            $statusByEquipment = ['U' => [], 'UD' => [], 'N' => []];
            foreach ($equipmentAxis as $row) {
                $y = (float) $row['y'] + ((float) ($row['height'] ?? 1.0) / 2.0);
                $status = $this->nearestStatus($columnWords, $y, 'y', $maxYDistance);
                if ($status !== null) $statusByEquipment[$status][] = $row['code'];
            }

            $results[] = [
                'code' => $control['code'],
                'description' => $this->descriptionForVerticalControl($words, $control, $columnTolerance),
                'results' => $this->cleanStatusResults($statusByEquipment),
            ];
        }

        return $results;
    }

    private function nearestStatus(array $words, float $target, string $axis, float $maxDistance): ?string
    {
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($words as $word) {
            $status = $this->normalizeStatus((string) ($word['text'] ?? ''));
            if ($status === null) continue;

            $center = (float) $word[$axis] + ((float) ($word[$axis === 'x' ? 'width' : 'height'] ?? 1.0) / 2.0);
            $distance = abs($center - $target);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $status;
            }
        }

        return $best !== null && $bestDistance <= $maxDistance ? $best : null;
    }

    private function descriptionForHorizontalControl(array $words, array $control, float $tolerance): ?string
    {
        $parts = [];
        foreach ($words as $word) {
            if (abs((float) $word['y'] - (float) $control['y']) > $tolerance) continue;
            $text = trim((string) $word['text']);
            if ($text === '' || $this->normalizeControlCode($text) === $this->normalizeControlCode($control['code']) || $this->normalizeStatus($text) !== null) continue;
            $parts[] = ['x' => (float) $word['x'], 'text' => $text];
        }
        usort($parts, fn($a, $b) => $a['x'] <=> $b['x']);
        return $parts ? trim(implode(' ', array_column($parts, 'text'))) : null;
    }

    private function descriptionForVerticalControl(array $words, array $control, float $tolerance): ?string
    {
        $parts = [];
        foreach ($words as $word) {
            if (abs((float) $word['x'] - (float) $control['x']) > $tolerance) continue;
            $text = trim((string) $word['text']);
            if ($text === '' || $this->normalizeControlCode($text) === $this->normalizeControlCode($control['code']) || $this->normalizeStatus($text) !== null) continue;
            $parts[] = ['y' => (float) $word['y'], 'text' => $text];
        }
        usort($parts, fn($a, $b) => $a['y'] <=> $b['y']);
        return $parts ? trim(implode(' ', array_column($parts, 'text'))) : null;
    }

    private function cleanStatusResults(array $results): array
    {
        foreach ($results as $status => $refs) {
            $results[$status] = array_values(array_unique($refs));
        }
        return $results;
    }

    private function medianGap(array $items, string $axis): float
    {
        $values = array_map(fn($item) => (float) $item[$axis], $items);
        sort($values);
        $gaps = [];
        for ($i = 1; $i < count($values); $i++) {
            if ($values[$i] > $values[$i - 1]) $gaps[] = $values[$i] - $values[$i - 1];
        }
        if (!$gaps) return 10.0;
        sort($gaps);
        return $gaps[(int) floor(count($gaps) / 2)];
    }

    private function axisRowTolerance(array $axis, string $coordinate): float
    {
        if (count($axis) <= 1) return 6.0;
        $gaps = [];
        $values = array_map(fn($item) => (float) $item[$coordinate], $axis);
        sort($values);
        for ($i = 1; $i < count($values); $i++) {
            if ($values[$i] > $values[$i - 1]) $gaps[] = $values[$i] - $values[$i - 1];
        }
        if (!$gaps) return 6.0;
        sort($gaps);
        return max(3.0, min(10.0, $gaps[(int) floor(count($gaps) / 2)] * 0.25));
    }

    private function coordinateTolerance(array $hits): float
    {
        $heights = array_values(array_filter(array_map(fn($h) => (float) ($h['height'] ?? 0), $hits), fn($v) => $v > 0));
        if (!$heights) return 5.0;
        sort($heights);
        return max(3.0, min(10.0, $heights[(int) floor(count($heights) / 2)] * 0.8));
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
        return str_replace(['–', '—', '‑', '−'], '-', $code);
    }

    private function normalizeControlCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/u', '', trim($code)));
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
