<?php

namespace App\Services\Ai;

class TemplateDrivenFireSuppressionCriterionExtractor
{
    public function __construct(
        private TemplateDrivenFireSuppressionMatrixExtractor $matrixExtractor,
        private CamelotPdfTableExtractor $camelot,
    ) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        $result = $this->matrixExtractor->extract($pdfPath, $semantic);
        $template = is_array($semantic['template'] ?? null) ? $semantic['template'] : [];
        $systems = (array) ($template['fire_systems']['systems'] ?? []);
        if (!$systems) return $result;

        $tables = array_values(array_filter(
            (array) ($this->camelot->extract($pdfPath)['tables'] ?? []),
            fn ($table) => is_array($table) && !empty($table['data']) && ($table['flavor'] ?? '') === 'lattice'
        ));

        foreach ($systems as $systemIndex => $system) {
            if (!is_array($system)) continue;
            $controls = (array) ($result['extracted_data']['fire_systems'][$systemIndex]['control_items'] ?? []);
            if (!$controls) continue;

            $templates = (array) ($system['control_items'] ?? []);
            foreach ($controls as $controlIndex => &$control) {
                if (!is_array($control)) continue;
                $code = $this->normalizeCode((string) ($control['code'] ?? ''));
                if ($code === '') continue;

                $templateControl = $this->findTemplate($code, $templates);
                if ($templateControl === null) continue;

                $pages = (array) ($control['source_pages'] ?? []);
                $criterion = $this->findCriterion($tables, $pages, $code, $templateControl);
                if ($criterion !== null) $control['criterion'] = $criterion;
            }
            unset($control);

            $result['extracted_data']['fire_systems'][$systemIndex]['control_items'] = $controls;
        }

        return $result;
    }

    private function findCriterion(array $tables, array $pages, string $code, array $template): ?string
    {
        $patterns = array_values(array_filter(array_map('strval', (array) ($template['control_text_patterns'] ?? [])), fn ($value) => trim($value) !== ''));
        if (!$patterns) return null;

        foreach ($tables as $table) {
            $page = (int) ($table['page'] ?? 0);
            if ($pages && !in_array($page, array_map('intval', $pages), true)) continue;

            $grid = array_values(array_map(
                fn ($row) => array_map(fn ($value) => trim((string) $value), (array) $row),
                (array) ($table['data'] ?? [])
            ));

            foreach ($grid as $row) {
                $codeColumn = null;
                foreach ($row as $column => $value) {
                    if ($this->matchesControlCode($value, $code, (array) ($template['control_code_patterns'] ?? []))) {
                        $codeColumn = (int) $column;
                        break;
                    }
                }
                if ($codeColumn === null) continue;

                $sameCell = $this->criterionFromCell((string) ($row[$codeColumn] ?? ''), $patterns, $code);
                if ($sameCell !== null) return $sameCell;

                foreach ($row as $column => $value) {
                    if ((int) $column === $codeColumn || trim((string) $value) === '') continue;
                    $candidate = $this->criterionFromCell((string) $value, $patterns, '');
                    if ($candidate !== null) return $candidate;
                }
            }
        }

        return null;
    }

    private function criterionFromCell(string $value, array $patterns, string $code): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') return null;

        $candidate = $value;
        if ($code !== '') {
            $candidate = trim(preg_replace('/^' . preg_quote($code, '/') . '\s*[:.)-]?\s*/iu', '', $candidate) ?? $candidate);
        }
        if ($candidate === '') return null;

        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($candidate, $pattern)) return $candidate;
        }

        return null;
    }

    private function matchesControlCode(string $value, string $code, array $patterns): bool
    {
        $value = trim($value);
        if ($value === '') return false;
        if ($this->normalizeCode($value) === $code) return true;

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') continue;
            if ($this->matchesPattern($value, $pattern) && $this->normalizeCode($this->extractCode($value)) === $code) return true;
        }

        return false;
    }

    private function extractCode(string $value): string
    {
        if (preg_match('/\b(\d+(?:\.\d+)+)\b/u', $value, $matches)) return $matches[1];
        return $value;
    }

    private function findTemplate(string $code, array $templates): ?array
    {
        foreach ($templates as $template) {
            if (!is_array($template)) continue;
            foreach ((array) ($template['control_code_patterns'] ?? []) as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern !== '' && ($this->normalizeCode($pattern) === $code || $this->normalizeCode($this->extractCode($pattern)) === $code)) return $template;
            }
        }
        return null;
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') return false;
        $matched = @preg_match($pattern, $value);
        if ($matched === 1) return true;
        return mb_stripos($value, $pattern) !== false;
    }

    private function normalizeCode(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\b(\d+(?:\.\d+)+)\b/u', $value, $matches)) return $matches[1];
        return $value;
    }
}
