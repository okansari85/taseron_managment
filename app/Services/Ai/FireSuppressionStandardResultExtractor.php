<?php

namespace App\Services\Ai;

/**
 * Reads non-matrix control rows from Camelot without extracting criteria.
 * Gemini owns code/criterion; Camelot only supplies the U/UD/N result and page.
 */
class FireSuppressionStandardResultExtractor
{
    public function __construct(private CamelotPdfTableExtractor $camelot)
    {
    }

    public function apply(string $pdfPath, array $semantic, array $camelotResult): array
    {
        $payload = $this->camelot->extract($pdfPath);
        $systems = (array) ($semantic['template']['fire_systems']['systems'] ?? []);
        $resultSystems = (array) ($camelotResult['extracted_data']['fire_systems'] ?? []);

        foreach ($systems as $system) {
            if (!is_array($system)) {
                continue;
            }

            $matrix = (array) ($system['control_matrix'] ?? []);
            if (($matrix['present'] ?? false) === true) {
                continue;
            }

            $systemName = trim((string) ($system['system_name'] ?? ''));
            if ($systemName === '') {
                continue;
            }

            $controlPatterns = [];
            foreach ((array) ($system['control_items'] ?? []) as $control) {
                if (!is_array($control)) {
                    continue;
                }
                foreach ((array) ($control['control_code_patterns'] ?? []) as $pattern) {
                    $pattern = trim((string) $pattern);
                    if ($pattern !== '') {
                        $controlPatterns[] = $pattern;
                    }
                }
            }

            if ($controlPatterns === []) {
                continue;
            }

            $controls = $this->extractControls($payload['tables'] ?? [], $controlPatterns);
            if ($controls === []) {
                continue;
            }

            $targetIndex = $this->findSystemIndex($resultSystems, $systemName);
            if ($targetIndex === null) {
                continue;
            }

            $existing = (array) ($resultSystems[$targetIndex]['control_items'] ?? []);
            $indexed = [];
            foreach ($existing as $control) {
                if (!is_array($control)) {
                    continue;
                }
                $code = $this->normalizeCode((string) ($control['code'] ?? ''));
                if ($code !== '') {
                    $indexed[$code] = $control;
                }
            }

            foreach ($controls as $control) {
                $code = $this->normalizeCode((string) ($control['code'] ?? ''));
                if ($code === '') {
                    continue;
                }

                $indexed[$code] = array_merge($indexed[$code] ?? [], $control);
            }

            $resultSystems[$targetIndex]['control_items'] = array_values($indexed);
        }

        $camelotResult['extracted_data']['fire_systems'] = array_values($resultSystems);
        return $camelotResult;
    }

    private function extractControls(array $tables, array $patterns): array
    {
        $out = [];

        foreach ($tables as $table) {
            if (!is_array($table) || empty($table['data'])) {
                continue;
            }

            $grid = (array) $table['data'];
            foreach ($grid as $row) {
                $row = array_values(array_map(static fn ($value): string => trim((string) $value), (array) $row));
                $count = count($row);

                for ($column = 0; $column < $count; $column++) {
                    $cellValue = $row[$column] ?? '';
                    $code = $this->matchCode($cellValue, $patterns);
                    if ($code === null) {
                        continue;
                    }

                    // Camelot can return a whole control as one merged cell,
                    // e.g. "5.12 Dizel pompa UD". In that case the result is
                    // in the same cell and must be bound to this code before
                    // looking at cells to the right.
                    $result = $this->resultForCodeInCell($cellValue, $code);

                    if ($result === null) {
                        $result = $this->nearestResultToRight($row, $column);
                    }

                    if ($result === null) {
                        continue;
                    }

                    $key = $this->normalizeCode($code);
                    $item = $out[$key] ?? [
                        'code' => $code,
                        'result' => null,
                        'source_pages' => [],
                    ];
                    $item['result'] = $result;
                    $item['source_pages'][] = (int) ($table['page'] ?? 0);
                    $item['source_pages'] = array_values(array_unique(array_filter(array_map('intval', $item['source_pages']))));
                    $out[$key] = $item;
                }
            }
        }

        return array_values($out);
    }

    private function resultForCodeInCell(string $cell, string $code): ?string
    {
        $cell = trim($cell);
        if ($cell === '') {
            return null;
        }

        $normalizedCode = preg_quote($this->normalizeCode($code), '/');

        // Stop before the next numbered control if two controls share a cell.
        $pattern = '/(?:^|\s)' . $normalizedCode . '(?=\s|$)(.*?)(?=\s+\d+\.\d+\b|$)/isu';
        if (preg_match($pattern, $cell, $match) !== 1) {
            return null;
        }

        $segment = trim((string) ($match[1] ?? ''));
        if ($segment === '') {
            return null;
        }

        // Only accept an exact U/UD/N token. This prevents criterion text
        // containing arbitrary letters from becoming a result.
        if (preg_match_all('/(?<![A-Za-zÇĞİÖŞÜçğıöşü])(UD|U|N)(?![A-Za-zÇĞİÖŞÜçğıöşü])/iu', $segment, $matches) > 0) {
            $last = end($matches[1]);
            return mb_strtoupper((string) $last, 'UTF-8');
        }

        return null;
    }

    private function nearestResultToRight(array $row, int $column): ?string
    {
        for ($index = $column + 1; $index < count($row); $index++) {
            $value = trim((string) ($row[$index] ?? ''));
            if ($value === '') {
                continue;
            }

            if (preg_match('/^(U|UD|N)$/iu', $value, $match) === 1) {
                return mb_strtoupper($match[1], 'UTF-8');
            }
        }

        return null;
    }

    private function matchCode(string $value, array $patterns): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            if ($this->normalizeCode($value) === $this->normalizeCode($pattern)) {
                return $value;
            }

            $regex = '~(?:^|\s)(' . $pattern . ')(?=\s|$)~iu';
            if (@preg_match($regex, $value, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private function findSystemIndex(array $systems, string $name): ?int
    {
        $needle = $this->normalizeLabel($name);
        foreach ($systems as $index => $system) {
            if (!is_array($system)) {
                continue;
            }
            if ($needle === $this->normalizeLabel((string) ($system['system_name'] ?? ''))) {
                return (int) $index;
            }
        }
        return null;
    }

    private function normalizeCode(string $value): string
    {
        $value = str_replace('\\.', '.', trim($value));
        if (preg_match('/\b(\d+\.\d+)\b/u', $value, $match) === 1) {
            return $match[1];
        }
        return $value;
    }

    private function normalizeLabel(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ı' => 'i', 'ğ' => 'g', 'ü' => 'u',
            'ş' => 's', 'ö' => 'o', 'ç' => 'c',
        ]);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
