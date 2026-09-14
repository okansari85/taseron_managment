<?php

namespace App\Services\Ai;

class TemplateDrivenFireSuppressionMatrixExtractor
{
    public function __construct(
        private TemplateDrivenFireSuppressionExtractor $baseExtractor,
        private CamelotPdfTableExtractor $camelot,
    ) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        $result = $this->baseExtractor->extract($pdfPath, $semantic);
        $template = is_array($semantic['template'] ?? null) ? $semantic['template'] : [];
        $systems = (array) ($template['fire_systems']['systems'] ?? []);
        if (!$systems) return $result;

        $camelot = $this->camelot->extract($pdfPath);
        $tables = array_values(array_filter((array) ($camelot['tables'] ?? []), fn ($table) => is_array($table) && !empty($table['data']) && ($table['flavor'] ?? '') === 'lattice'));

        foreach ($systems as $systemIndex => $system) {
            if (!is_array($system)) continue;
            $controlMatrix = (array) ($system['control_matrix'] ?? []);
            if (($controlMatrix['present'] ?? false) === true) {
                $matrixControls = $this->extractMatrixControls($tables, $system);
                if ($matrixControls !== []) {
                    $result['extracted_data']['fire_systems'][$systemIndex]['control_items'] = $matrixControls;
                    continue;
                }
            }
            $result['extracted_data']['fire_systems'][$systemIndex]['control_items'] = $this->addCriteria(
                (array) ($result['extracted_data']['fire_systems'][$systemIndex]['control_items'] ?? []),
                (array) ($system['control_items'] ?? [])
            );
        }
        return $result;
    }

    private function extractMatrixControls(array $tables, array $system): array
    {
        $controlTemplates = (array) ($system['control_items'] ?? []);
        if (!$controlTemplates) return [];
        $matrixTemplate = (array) ($system['control_matrix'] ?? []);
        $camelotTemplate = (array) ($matrixTemplate['camelot_extraction'] ?? []);
        $equipmentHeaderPatterns = $this->patterns($camelotTemplate['equipment_header_patterns'] ?? []);
        $controlCodePatterns = $this->patterns($camelotTemplate['control_code_patterns'] ?? []);
        $controlLabelPatterns = $this->patterns($camelotTemplate['control_label_patterns'] ?? []);
        $resultPatterns = $this->patterns($camelotTemplate['result_cell_patterns'] ?? []);
        foreach ((array) ($system['equipment'] ?? []) as $equipment) {
            if (is_array($equipment)) $equipmentHeaderPatterns = array_merge($equipmentHeaderPatterns, $this->patterns($equipment['camelot_extraction']['equipment_header_patterns'] ?? []));
        }
        $equipmentHeaderPatterns = array_values(array_unique($equipmentHeaderPatterns));
        if (!$equipmentHeaderPatterns || !$controlCodePatterns || !$resultPatterns) return [];

        $candidates = [];
        foreach ($tables as $table) {
            $grid = $this->matrix($table);
            if (!$grid) continue;
            foreach (['equipment_columns', 'equipment_rows'] as $orientation) {
                $candidate = $this->extractMatrixOrientation($grid, (int) ($table['page'] ?? 0), $orientation, $equipmentHeaderPatterns, $controlTemplates, $controlCodePatterns, $controlLabelPatterns, $resultPatterns, $system);
                if ($candidate['score'] > 0) $candidates[] = $candidate;
            }
        }
        if (!$candidates) return [];
        usort($candidates, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        if ($candidates[0]['score'] < 2 || !$candidates[0]['controls']) return [];

        $controls = [];
        foreach ($candidates as $candidate) {
            if ($candidate['score'] < $candidates[0]['score'] - 2) continue;
            foreach ($candidate['controls'] as $control) {
                $key = $this->normalizeCode((string) ($control['code'] ?? ''));
                if ($key === '') continue;
                if (!isset($controls[$key])) $controls[$key] = $control;
                else {
                    $controls[$key]['results'] = $this->mergeResults((array) ($controls[$key]['results'] ?? []), (array) ($control['results'] ?? []));
                    $controls[$key]['source_pages'] = array_values(array_unique(array_merge((array) ($controls[$key]['source_pages'] ?? []), (array) ($control['source_pages'] ?? []))));
                }
            }
        }
        return array_values($controls);
    }

    private function extractMatrixOrientation(array $grid, int $page, string $orientation, array $equipmentHeaderPatterns, array $controlTemplates, array $controlCodePatterns, array $controlLabelPatterns, array $resultPatterns, array $system): array
    {
        $matrixTemplate = (array) ($system['control_matrix'] ?? []);
        $axisDetection = (array) ($matrixTemplate['axis_detection'] ?? []);
        $equipmentAxis = (array) ($axisDetection['equipment_axis'] ?? []);
        $controlAxis = (array) ($axisDetection['control_axis'] ?? []);
        $equipmentPosition = $this->normalizePosition((string) (($matrixTemplate['matrix_relationship']['equipment_position'] ?? '')));
        $declaredOrientation = $this->normalizeOrientation((string) ($matrixTemplate['orientation'] ?? ''));
        $axis = $orientation === 'equipment_columns' ? 'columns' : 'rows';
        $score = 0;
        if ($equipmentPosition !== null && $equipmentPosition === $axis) $score += 2;
        if ($declaredOrientation !== null && $declaredOrientation === $orientation) $score += 2;
        $controls = $orientation === 'equipment_columns'
            ? $this->readEquipmentColumns($grid, $page, $equipmentHeaderPatterns, $controlTemplates, $controlCodePatterns, $controlLabelPatterns, $resultPatterns)
            : $this->readEquipmentRows($grid, $page, $equipmentHeaderPatterns, $controlTemplates, $controlCodePatterns, $controlLabelPatterns, $resultPatterns);
        if ($controls) $score += min(10, count($controls));
        foreach ($this->patterns($equipmentAxis['detection_patterns'] ?? []) as $pattern) {
            foreach ($grid as $row) if ($this->matchesAny($this->rowText($row), [$pattern])) { $score++; break 2; }
        }
        foreach ($this->patterns($controlAxis['detection_patterns'] ?? []) as $pattern) {
            foreach ($grid as $row) if ($this->matchesAny($this->rowText($row), [$pattern])) { $score++; break 2; }
        }
        return ['score' => $score, 'orientation' => $orientation, 'controls' => $controls];
    }

    private function readEquipmentColumns(array $grid, int $page, array $equipmentHeaderPatterns, array $controlTemplates, array $controlCodePatterns, array $controlLabelPatterns, array $resultPatterns): array
    {
        foreach ($grid as $headerRowIndex => $row) {
            $headerColumn = $this->findPatternColumn($row, $equipmentHeaderPatterns);
            if ($headerColumn === null) continue;
            $equipmentColumns = [];
            foreach ($row as $column => $value) {
                if ((int) $column <= $headerColumn) continue;
                $code = $this->firstEquipmentCode([$value]);
                if ($code !== null) $equipmentColumns[(int) $column] = $code;
            }
            if (!$equipmentColumns) continue;
            $controls = [];
            for ($r = $headerRowIndex + 1; $r < count($grid); $r++) {
                $control = $this->findControlInRow($grid[$r], $controlTemplates, $controlCodePatterns);
                if ($control === null) continue;
                $results = [];
                foreach ($equipmentColumns as $column => $equipmentCode) {
                    $result = $this->matchResultValue((string) ($grid[$r][$column] ?? ''), $resultPatterns);
                    if ($result !== null) $results[] = ['equipment_code' => $equipmentCode, 'result' => $result, 'source_pages' => [$page]];
                }
                if ($results) $controls[] = ['code' => $control['code'], 'criterion' => $control['criterion'], 'results' => $results, 'source_pages' => [$page]];
            }
            if ($controls) return $controls;
        }
        return [];
    }

    private function readEquipmentRows(array $grid, int $page, array $equipmentHeaderPatterns, array $controlTemplates, array $controlCodePatterns, array $controlLabelPatterns, array $resultPatterns): array
    {
        $maxColumns = $grid ? max(array_map('count', $grid)) : 0;
        for ($headerColumn = 0; $headerColumn < $maxColumns; $headerColumn++) {
            $headerFound = false;
            foreach ($grid as $row) if ($this->matchesAny((string) ($row[$headerColumn] ?? ''), $equipmentHeaderPatterns)) { $headerFound = true; break; }
            if (!$headerFound) continue;
            $equipmentRows = [];
            foreach ($grid as $rowIndex => $row) {
                $code = $this->firstEquipmentCode(array_slice($row, $headerColumn + 1));
                if ($code !== null) $equipmentRows[$rowIndex] = $code;
            }
            if (!$equipmentRows) continue;
            $controlHeader = $this->findControlHeaderRow($grid, $controlTemplates, $controlCodePatterns);
            if ($controlHeader === null) continue;
            $controlColumns = [];
            foreach ($grid[$controlHeader] as $column => $value) {
                $control = $this->findControlInCell((string) $value, $controlTemplates, $controlCodePatterns);
                if ($control !== null) $controlColumns[$column] = $control;
            }
            if (!$controlColumns) continue;
            $controls = [];
            foreach ($controlColumns as $column => $control) {
                $results = [];
                foreach ($equipmentRows as $rowIndex => $equipmentCode) {
                    $result = $this->matchResultValue((string) ($grid[$rowIndex][$column] ?? ''), $resultPatterns);
                    if ($result !== null) $results[] = ['equipment_code' => $equipmentCode, 'result' => $result, 'source_pages' => [$page]];
                }
                if ($results) $controls[] = ['code' => $control['code'], 'criterion' => $control['criterion'], 'results' => $results, 'source_pages' => [$page]];
            }
            if ($controls) return $controls;
        }
        return [];
    }

    private function findControlHeaderRow(array $grid, array $controlTemplates, array $controlCodePatterns): ?int
    {
        foreach ($grid as $rowIndex => $row) {
            foreach ($row as $value) if ($this->findControlInCell((string) $value, $controlTemplates, $controlCodePatterns) !== null) return $rowIndex;
        }
        return null;
    }

    private function findControlInRow(array $row, array $controlTemplates, array $controlCodePatterns): ?array
    {
        foreach ($row as $value) {
            $control = $this->findControlInCell((string) $value, $controlTemplates, $controlCodePatterns);
            if ($control !== null) return $control;
        }
        return null;
    }

    private function findControlInCell(string $value, array $controlTemplates, array $controlCodePatterns): ?array
    {
        $value = trim($value);
        if ($value === '') return null;
        foreach ($controlTemplates as $control) {
            if (!is_array($control)) continue;
            $code = $this->matchControlCode($value, (array) ($control['control_code_patterns'] ?? []));
            if ($code !== null) return ['code' => $code, 'criterion' => $this->criterionFromCell($value, $control)];
        }
        $code = $this->matchControlCode($value, $controlCodePatterns);
        return $code !== null ? ['code' => $code, 'criterion' => $this->stripControlCode($value, $code)] : null;
    }

    private function addCriteria(array $controls, array $templates): array
    {
        foreach ($controls as &$item) {
            $template = $this->findControlTemplate($this->normalizeCode((string) ($item['code'] ?? '')), $templates);
            if ($template !== null) $item['criterion'] = $this->criterionFromTemplate($template);
        }
        unset($item);
        return $controls;
    }

    private function findControlTemplate(string $code, array $templates): ?array
    {
        foreach ($templates as $template) {
            if (!is_array($template)) continue;
            foreach ((array) ($template['control_code_patterns'] ?? []) as $pattern) if ($this->normalizeCode((string) $pattern) === $code || $this->matchControlCode($code, [(string) $pattern]) !== null) return $template;
        }
        return null;
    }

    private function criterionFromTemplate(array $template): ?string
    {
        foreach ((array) ($template['control_text_patterns'] ?? []) as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern !== '' && !preg_match('/^[\^$\\[\]().*+?{}|]+$/u', $pattern)) return $this->clean($pattern);
        }
        return null;
    }

    private function criterionFromCell(string $value, array $template): ?string
    {
        foreach ((array) ($template['control_text_patterns'] ?? []) as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern !== '' && $this->matchesAny($value, [$pattern])) {
                $candidate = $this->stripControlCode($value, (string) ($this->matchControlCode($value, (array) ($template['control_code_patterns'] ?? [])) ?? ''));
                return $this->clean($candidate);
            }
        }
        return $this->stripControlCode($value, (string) ($this->matchControlCode($value, (array) ($template['control_code_patterns'] ?? [])) ?? ''));
    }

    private function stripControlCode(string $value, string $code): ?string
    {
        $value = trim($value);
        if ($code !== '') $value = trim(preg_replace('/^' . preg_quote($code, '/') . '\s*[:.)-]?\s*/iu', '', $value) ?? $value);
        return $value !== '' ? $value : null;
    }

    private function mergeResults(array $left, array $right): array
    {
        $out = $left;
        $seen = [];
        foreach ($out as $item) $seen[$this->normalizeCode((string) ($item['equipment_code'] ?? ''))] = true;
        foreach ($right as $item) {
            $key = $this->normalizeCode((string) ($item['equipment_code'] ?? ''));
            if ($key !== '' && !isset($seen[$key])) { $out[] = $item; $seen[$key] = true; }
        }
        return $out;
    }

    private function firstEquipmentCode(array $values): ?string
    {
        foreach ($values as $value) {
            $value = trim(str_replace(["\n", "\r"], ' ', (string) $value));
            if ($value === '' || $value === '-') continue;
            foreach (preg_split('/\s+/u', $value) ?: [] as $token) {
                $token = trim($token, " ,;");
                if ($token !== '' && preg_match('/^(?:[A-ZÇĞİÖŞÜ]{1,8}[ -]?\d+|\d+)$/u', $token) === 1) return $token;
            }
        }
        return null;
    }

    private function patterns(mixed $patterns): array { return array_values(array_filter(array_map('strval', (array) $patterns), fn ($v) => trim($v) !== '')); }
    private function matrix(array $table): array { return array_values(array_map(fn ($row) => array_map(fn ($value) => trim((string) $value), (array) $row), (array) ($table['data'] ?? []))); }
    private function rowText(array $row): string { return trim(implode(' ', array_values(array_filter(array_map('strval', $row), fn ($v) => trim($v) !== '')))); }
    private function findPatternColumn(array $row, array $patterns): ?int { foreach ($row as $index => $value) if ($this->matchesAny((string) $value, $patterns)) return (int) $index; return null; }
    private function matchesAny(string $value, array $patterns): bool { foreach ($patterns as $pattern) if (@preg_match($pattern, $value) === 1 || @preg_match('~' . $pattern . '~iu', $value) === 1 || $this->normalizeLabel($pattern) === $this->normalizeLabel($value)) return true; return false; }
    private function matchControlCode(string $value, array $patterns): ?string { $value = trim($value); if ($value === '') return null; foreach ($patterns as $pattern) { $pattern = trim((string) $pattern); if ($pattern !== '' && ($this->normalizeCode($value) === $this->normalizeCode($pattern) || @preg_match($pattern, $value) === 1 || @preg_match('~' . $pattern . '~iu', $value) === 1)) return $value; } return null; }
    private function matchResultValue(string $value, array $patterns): ?string { $value = trim($value); if ($value === '') return null; foreach ($patterns as $pattern) if ($this->normalizeLabel($value) === $this->normalizeLabel((string) $pattern) || @preg_match($pattern, $value) === 1 || @preg_match('~' . $pattern . '~iu', $value) === 1) return $value; return null; }
    private function normalizeLabel(string $value): string { $value = mb_strtolower(trim($value), 'UTF-8'); return rtrim(preg_replace('/\s+/u', ' ', $value) ?? $value, ':'); }
    private function normalizeCode(string $value): string { return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? '', 'UTF-8'); }
    private function normalizePosition(string $value): ?string { $value = mb_strtolower(trim($value), 'UTF-8'); if (in_array($value, ['column', 'columns', 'x'], true)) return 'columns'; if (in_array($value, ['row', 'rows', 'y'], true)) return 'rows'; return null; }
    private function normalizeOrientation(string $value): ?string { $value = mb_strtolower(trim($value), 'UTF-8'); if (str_contains($value, 'equipment') && str_contains($value, 'column')) return 'equipment_columns'; if (str_contains($value, 'equipment') && str_contains($value, 'row')) return 'equipment_rows'; return null; }
    private function clean(?string $value): ?string { if ($value === null) return null; $value = trim(preg_replace('/\s+/u', ' ', str_replace(["\n", "\r"], ' ', $value)) ?? $value); return $value === '' ? null : $value; }
}
