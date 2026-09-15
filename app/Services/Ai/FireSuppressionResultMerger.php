<?php

namespace App\Services\Ai;

/**
 * Final authority split:
 * Gemini  -> system_name, code, criterion, findings, overall_result
 * Camelot -> equipment, matrix results (U/UD/N), value, source_pages
 */
class FireSuppressionResultMerger
{
    public function merge(array $camelotResult, array $semantic): array
    {
        $geminiData = (array) ($semantic['data']['extracted_data']
            ?? $semantic['extracted_data']
            ?? []);

        $templateSystems = array_values(array_filter(
            (array) ($semantic['template']['fire_systems']['systems'] ?? []),
            'is_array'
        ));

        $geminiSystems = array_values(array_filter(
            (array) ($geminiData['fire_systems'] ?? []),
            'is_array'
        ));

        // In the current Gemini contract, the semantic control definitions
        // live under template.fire_systems.systems. Prefer concrete
        // extracted_data systems when they exist, otherwise use that Gemini
        // template tree as the semantic source.
        if ($geminiSystems === []) {
            $geminiSystems = $templateSystems;
        }

        $camelotSystems = array_values(array_filter(
            (array) ($camelotResult['extracted_data']['fire_systems'] ?? []),
            'is_array'
        ));

        $finalSystems = [];

        foreach ($geminiSystems as $geminiSystem) {
            $systemName = trim((string) ($geminiSystem['system_name'] ?? $geminiSystem['name'] ?? ''));
            if ($systemName === '') {
                continue;
            }

            $templateSystem = $this->findSystem($templateSystems, $systemName) ?? $geminiSystem;
            $camelotSystem = $this->findSystem($camelotSystems, $systemName);

            $camelotEquipment = $camelotSystem !== null
                ? (array) ($camelotSystem['equipment'] ?? [])
                : [];

            $camelotControls = $this->indexControls(
                $camelotSystem !== null ? (array) ($camelotSystem['control_items'] ?? []) : []
            );

            $geminiControls = (array) ($geminiSystem['control_items'] ?? []);
            if ($geminiControls === [] && $templateSystem !== $geminiSystem) {
                $geminiControls = (array) ($templateSystem['control_items'] ?? []);
            }

            // Gemini may return either concrete controls or compact template
            // patterns. Materialize patterns here. Criterion is always taken
            // from Gemini's control_text_patterns, by the same index as the
            // expanded code; it is never taken from Camelot/PDF geometry.
            $materializedControls = $this->materializeGeminiControls($geminiControls);
            $finalControls = [];

            foreach ($materializedControls as $geminiControl) {
                $code = $this->concreteCode($geminiControl);
                if ($code === '') {
                    continue;
                }

                $criterion = $this->concreteCriterion($geminiControl);
                $finalControl = $geminiControl;
                $finalControl['code'] = $code;
                $finalControl['criterion'] = $criterion;

                unset(
                    $finalControl['control_code_patterns'],
                    $finalControl['control_text_patterns'],
                    $finalControl['result_patterns']
                );

                // Camelot contributes only the inspection result data.
                if (isset($camelotControls[$code])) {
                    $camelotControl = $camelotControls[$code];
                    $results = (array) ($camelotControl['results'] ?? []);

                    if ($results !== []) {
                        $finalControl['results'] = $this->mergeResults($results);
                        $pages = $this->pagesFromResults($results);
                        if ($pages !== []) {
                            $finalControl['source_pages'] = $pages;
                        }
                    }
                }

                $finalControls[$code] = $finalControl;
            }

            $finalSystem = $geminiSystem;
            $finalSystem['system_name'] = $systemName;
            $finalSystem['equipment'] = array_values($camelotEquipment);
            $finalSystem['control_items'] = array_values($finalControls);
            $finalSystems[] = $finalSystem;
        }

        $final = $camelotResult;
        $final['extracted_data']['fire_systems'] = $finalSystems;

        if (array_key_exists('findings', $geminiData)) {
            $final['extracted_data']['findings'] = (array) $geminiData['findings'];
        }

        if (array_key_exists('overall_result', $geminiData)) {
            $final['extracted_data']['overall_result'] = $geminiData['overall_result'];
            $final['extracted_data']['report']['overall_result'] = $geminiData['overall_result'];
        } elseif (isset($camelotResult['extracted_data']['overall_result'])) {
            $final['extracted_data']['overall_result'] = $camelotResult['extracted_data']['overall_result'];
        }

        return $final;
    }

    /**
     * Converts Gemini's compact control definitions into one item per code.
     * The criterion is paired by position with the expanded code list.
     */
    private function materializeGeminiControls(array $controls): array
    {
        $materialized = [];

        foreach ($controls as $control) {
            if (!is_array($control)) {
                continue;
            }

            // Already concrete: preserve the Gemini item as-is.
            $directCode = $this->directCode($control);
            if ($directCode !== '') {
                $item = $control;
                $item['code'] = $directCode;
                $materialized[] = $item;
                continue;
            }

            $codes = $this->expandCodePatterns((array) ($control['control_code_patterns'] ?? []));
            $criteria = array_values(array_map(
                static fn ($value): string => trim((string) $value),
                (array) ($control['control_text_patterns'] ?? [])
            ));

            foreach ($codes as $index => $code) {
                $item = $control;
                $item['code'] = $code;

                // The Gemini template supplies criterion definitions in the
                // same order as its code pattern alternatives.
                if (isset($criteria[$index]) && $criteria[$index] !== '') {
                    $item['criterion'] = $criteria[$index];
                }

                $materialized[] = $item;
            }
        }

        return $materialized;
    }

    private function directCode(array $control): string
    {
        foreach (['code', 'control_code'] as $key) {
            $value = trim((string) ($control[$key] ?? ''));
            if ($value !== '' && !$this->looksLikePattern($value)) {
                return $this->normalizeCode($value);
            }
        }

        return '';
    }

    private function expandCodePatterns(array $patterns): array
    {
        $codes = [];

        foreach ($patterns as $pattern) {
            $value = trim((string) $pattern);
            if ($value === '') {
                continue;
            }

            // Gemini escapes the literal dot as 5\.(...). Normalize only
            // that escape; do not treat the remaining expression as a regex.
            $value = str_replace('\\.', '.', $value);
            $value = preg_replace('/\s+/u', '', $value) ?? $value;

            // 5.[1-3]
            if (preg_match('/^5\.\[(\d+)-(\d+)\]$/', $value, $match) === 1) {
                $start = (int) $match[1];
                $end = (int) $match[2];
                for ($number = $start; $number <= $end; $number++) {
                    $codes[] = '5.' . $number;
                }
                continue;
            }

            // 5.(4|5|6|...|23)
            if (preg_match('/^5\.\(([^)]+)\)$/', $value, $match) === 1) {
                foreach (explode('|', $match[1]) as $part) {
                    $part = trim($part);
                    if (ctype_digit($part)) {
                        $codes[] = '5.' . (int) $part;
                    }
                }
                continue;
            }

            // Concrete code, e.g. 5.24 / 5.53.
            if (preg_match('/^5\.\d+$/', $value) === 1) {
                $codes[] = $value;
                continue;
            }

            // Fallback for an ordinary concrete code from Gemini.
            $normalized = $this->normalizeCode($value);
            if ($normalized !== '' && !$this->looksLikePattern($normalized)) {
                $codes[] = $normalized;
            }
        }

        return array_values(array_unique($codes));
    }

    private function looksLikePattern(string $value): bool
    {
        return str_contains($value, '|')
            || str_contains($value, '(')
            || str_contains($value, ')')
            || str_contains($value, '[')
            || str_contains($value, ']');
    }

    private function indexControls(array $controls): array
    {
        $indexed = [];

        foreach ($controls as $control) {
            if (!is_array($control)) {
                continue;
            }

            $code = $this->directCode($control);
            if ($code !== '') {
                $indexed[$code] = $control;
                continue;
            }

            foreach ($this->expandCodePatterns((array) ($control['control_code_patterns'] ?? [])) as $expandedCode) {
                $indexed[$expandedCode] = $control;
            }
        }

        return $indexed;
    }

    private function concreteCode(array $control): string
    {
        $code = $this->directCode($control);
        if ($code !== '') {
            return $code;
        }

        $codes = $this->expandCodePatterns((array) ($control['control_code_patterns'] ?? []));
        return $codes[0] ?? '';
    }

    private function concreteCriterion(array $control): ?string
    {
        foreach (['criterion', 'control_text', 'text', 'description'] as $key) {
            if (array_key_exists($key, $control) && trim((string) $control[$key]) !== '') {
                return trim((string) $control[$key]);
            }
        }

        // For a materialized pattern item this is the single criterion that
        // was paired by index in materializeGeminiControls().
        return null;
    }

    private function findSystem(array $systems, string $systemName): ?array
    {
        $key = $this->normalizeLabel($systemName);
        if ($key === '') {
            return null;
        }

        foreach ($systems as $system) {
            if (!is_array($system)) {
                continue;
            }

            $candidate = $this->normalizeLabel(
                (string) ($system['system_name'] ?? $system['name'] ?? '')
            );

            if ($candidate === $key) {
                return $system;
            }
        }

        return null;
    }

    private function normalizeCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace('\\.', '.', $value);

        if (preg_match('/\b(\d+(?:\.\d+)+)\b/u', $value, $match) === 1) {
            return $match[1];
        }

        return mb_strtoupper(preg_replace('/\s+/u', '', $value) ?? '', 'UTF-8');
    }

    private function normalizeLabel(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ı' => 'i',
            'ğ' => 'g',
            'ü' => 'u',
            'ş' => 's',
            'ö' => 'o',
            'ç' => 'c',
        ]);

        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value
            ) ?? $value
        );
    }

    private function mergeResults(array $results): array
    {
        $merged = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $equipmentCode = trim((string) ($result['equipment_code'] ?? ''));
            if ($equipmentCode === '') {
                continue;
            }

            $merged[mb_strtoupper($equipmentCode, 'UTF-8')] = $result;
        }

        return array_values($merged);
    }

    private function pagesFromResults(array $results): array
    {
        $pages = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            foreach ((array) ($result['source_pages'] ?? []) as $page) {
                $page = (int) $page;
                if ($page > 0) {
                    $pages[] = $page;
                }
            }
        }

        return array_values(array_unique($pages));
    }
}
