<?php

namespace App\Services\Ai;

/**
 * Extracts concrete control criteria from Camelot cell geometry.
 * Gemini supplies the system/code/result patterns; Camelot supplies the
 * physical cell boundaries. Existing extraction and merge methods stay intact.
 */
class CamelotCriterionNormalizer
{
    public function __construct(private CamelotPdfTableExtractor $camelot)
    {
    }

    public function normalize(array $semantic, string $pdfPath): array
    {
        $systems = (array) ($semantic['template']['fire_systems']['systems'] ?? []);
        if ($systems === []) {
            return $semantic;
        }

        $camelot = $this->camelot->extract($pdfPath);
        $tables = array_values(array_filter(
            (array) ($camelot['tables'] ?? []),
            static fn ($table): bool => is_array($table)
                && !empty($table['cells'])
                && ($table['flavor'] ?? '') === 'lattice'
        ));

        if ($tables === []) {
            return $semantic;
        }

        foreach ($systems as $systemIndex => $system) {
            if (!is_array($system)) {
                continue;
            }

            $controls = $this->extractControlsForSystem($tables, $system);
            if ($controls !== []) {
                $semantic['template']['fire_systems']['systems'][$systemIndex]['control_items'] = $controls;
            }
        }

        return $semantic;
    }

    private function extractControlsForSystem(array $tables, array $system): array
    {
        $controlTemplates = array_values(array_filter((array) ($system['control_items'] ?? []), 'is_array'));
        $codePatterns = [];
        $resultPatterns = [];

        foreach ($controlTemplates as $template) {
            $codePatterns = array_merge($codePatterns, $this->patterns($template['control_code_patterns'] ?? []));
            $resultPatterns = array_merge($resultPatterns, $this->patterns($template['result_patterns'] ?? []));
        }

        $matrix = (array) ($system['control_matrix'] ?? []);
        $camelotTemplate = (array) ($matrix['camelot_extraction'] ?? []);
        $codePatterns = array_values(array_unique(array_merge(
            $codePatterns,
            $this->patterns($camelotTemplate['control_code_patterns'] ?? [])
        )));
        $resultPatterns = array_values(array_unique(array_merge(
            $resultPatterns,
            $this->patterns($camelotTemplate['result_cell_patterns'] ?? [])
        )));

        if ($codePatterns === [] || $resultPatterns === []) {
            return [];
        }

        $controls = [];
        foreach ($tables as $table) {
            $cells = (array) ($table['cells'] ?? []);
            $page = (int) ($table['page'] ?? 0);

            foreach ($cells as $rowIndex => $rowCells) {
                foreach ($rowCells as $columnIndex => $cell) {
                    if (!is_array($cell)) {
                        continue;
                    }

                    $value = $this->clean($cell['text'] ?? '');
                    if ($value === null) {
                        continue;
                    }

                    $code = $this->matchControlCode($value, $codePatterns);
                    if ($code === null) {
                        continue;
                    }

                    $criterion = $this->criterionBetweenCodeAndResult(
                        $cells,
                        (int) $rowIndex,
                        (int) $columnIndex,
                        $cell,
                        $code,
                        $resultPatterns,
                        $codePatterns
                    );

                    if ($criterion === null) {
                        continue;
                    }

                    $key = $this->normalizeCode($code);
                    if (!isset($controls[$key])) {
                        $controls[$key] = [
                            'code' => $code,
                            'criterion' => $criterion,
                            'source_pages' => [$page],
                        ];
                    } else {
                        $controls[$key]['source_pages'] = array_values(array_unique(array_merge(
                            (array) $controls[$key]['source_pages'], [$page]
                        )));
                        if (mb_strlen($criterion, 'UTF-8') > mb_strlen((string) $controls[$key]['criterion'], 'UTF-8')) {
                            $controls[$key]['criterion'] = $criterion;
                        }
                    }
                }
            }
        }

        uksort($controls, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        return array_values($controls);
    }

    private function criterionBetweenCodeAndResult(
        array $cells,
        int $rowIndex,
        int $columnIndex,
        array $codeCell,
        string $code,
        array $resultPatterns,
        array $codePatterns
    ): ?string {
        $sameCell = $this->stripControlCode($this->clean($codeCell['text'] ?? ''), $code);
        if ($sameCell !== null) {
            return $sameCell;
        }

        $cx1 = (float) ($codeCell['x1'] ?? 0);
        $cx2 = (float) ($codeCell['x2'] ?? 0);
        $cy1 = (float) ($codeCell['y1'] ?? 0);
        $cy2 = (float) ($codeCell['y2'] ?? 0);

        $rightResults = [];
        $verticalResults = [];
        $horizontalCandidates = [];
        $verticalCandidates = [];

        foreach ($cells as $r => $rowCells) {
            foreach ($rowCells as $c => $candidateCell) {
                if (!is_array($candidateCell) || ((int) $r === $rowIndex && (int) $c === $columnIndex)) {
                    continue;
                }

                $text = $this->clean($candidateCell['text'] ?? '');
                if ($text === null) {
                    continue;
                }

                if ($this->matchControlCode($text, $codePatterns) !== null) {
                    continue;
                }

                $x1 = (float) ($candidateCell['x1'] ?? 0);
                $x2 = (float) ($candidateCell['x2'] ?? 0);
                $y1 = (float) ($candidateCell['y1'] ?? 0);
                $y2 = (float) ($candidateCell['y2'] ?? 0);

                $xOverlap = min($cx2, $x2) - max($cx1, $x1);
                $yOverlap = min($cy2, $y2) - max($cy1, $y1);
                $xRatio = max(0, $xOverlap) / max(0.01, min(abs($cx2 - $cx1), abs($x2 - $x1)));
                $yRatio = max(0, $yOverlap) / max(0.01, min(abs($cy2 - $cy1), abs($y2 - $y1)));

                if ($this->matchResultValue($text, $resultPatterns) !== null) {
                    if ($x1 >= $cx2 && $yRatio > 0.35) {
                        $rightResults[] = ['cell' => $candidateCell, 'gap' => $x1 - $cx2];
                    }
                    if ($y1 >= $cy2 && $xRatio > 0.35) {
                        $verticalResults[] = ['cell' => $candidateCell, 'gap' => $y1 - $cy2];
                    }
                    continue;
                }

                if ($this->looksLikeEquipmentCode($text)) {
                    continue;
                }

                if ($x1 >= $cx2 && $yRatio > 0.35) {
                    $horizontalCandidates[] = [
                        'text' => $text,
                        'x1' => $x1,
                        'gap' => $x1 - $cx2,
                    ];
                } elseif ($y1 >= $cy2 && $xRatio > 0.35) {
                    $verticalCandidates[] = [
                        'text' => $text,
                        'y1' => $y1,
                        'gap' => $y1 - $cy2,
                    ];
                }
            }
        }

        if ($rightResults !== []) {
            usort($rightResults, static fn (array $a, array $b): int => $a['gap'] <=> $b['gap']);
            $boundary = (float) $rightResults[0]['cell']['x1'];
            $parts = array_values(array_filter(
                $horizontalCandidates,
                static fn (array $item): bool => $item['x1'] < $boundary
            ));
            usort($parts, static fn (array $a, array $b): int => $a['x1'] <=> $b['x1']);
            $criterion = $this->joinParts(array_column($parts, 'text'));
            if ($criterion !== null) {
                return $criterion;
            }
        }

        if ($verticalResults !== []) {
            usort($verticalResults, static fn (array $a, array $b): int => $a['gap'] <=> $b['gap']);
            $boundary = (float) $verticalResults[0]['cell']['y1'];
            $parts = array_values(array_filter(
                $verticalCandidates,
                static fn (array $item): bool => $item['y1'] < $boundary
            ));
            usort($parts, static fn (array $a, array $b): int => $a['y1'] <=> $b['y1']);
            $criterion = $this->joinParts(array_column($parts, 'text'));
            if ($criterion !== null) {
                return $criterion;
            }
        }

        return null;
    }

    private function joinParts(array $parts): ?string
    {
        $out = [];
        foreach ($parts as $part) {
            $part = $this->clean($part);
            if ($part === null) {
                continue;
            }
            if ($out !== [] && $this->normalizeLabel(end($out)) === $this->normalizeLabel($part)) {
                continue;
            }
            $out[] = $part;
        }
        return $this->clean(implode(' ', $out));
    }

    private function matchControlCode(string $value, array $patterns): ?string
    {
        $value = trim(str_replace(["\n", "\r"], ' ', $value));
        if ($value === '') {
            return null;
        }

        $valueCode = null;
        if (preg_match('/^\s*([A-Za-zÇĞİÖŞÜ]{0,8}[ -]?\d+(?:[.\-]\d+)*)\b/u', $value, $m) === 1) {
            $valueCode = trim($m[1]);
        }

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            if (@preg_match($pattern, $value) === 1 || @preg_match('~' . $pattern . '~iu', $value) === 1) {
                return $valueCode ?? $pattern;
            }
        }

        return null;
    }

    private function matchResultValue(string $value, array $patterns): ?string
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern !== '' && (
                $this->normalizeLabel($value) === $this->normalizeLabel($pattern)
                || @preg_match($pattern, $value) === 1
                || @preg_match('~' . $pattern . '~iu', $value) === 1
            )) {
                return $value;
            }
        }
        return null;
    }

    private function looksLikeEquipmentCode(string $value): bool
    {
        return preg_match('/^[A-ZÇĞİÖŞÜ]{1,8}[ -]?\d+$/u', trim($value)) === 1;
    }

    private function stripControlCode(?string $value, string $code): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace(
            '/^' . preg_quote($code, '/') . '\\s*[:.)-]?\\s*/iu',
            '',
            trim($value)
        ) ?? $value);
        return $value !== '' ? $value : null;
    }

    private function patterns(mixed $patterns): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) $patterns
        ), static fn (string $value): bool => $value !== ''));
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = str_replace(["\r", "\n"], ' ', (string) $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B|:");
        return $value === '' ? null : $value;
    }

    private function normalizeCode(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? '', 'UTF-8');
    }

    private function normalizeLabel(string $value): string
    {
        return rtrim(mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '', 'UTF-8'), ':');
    }
}
