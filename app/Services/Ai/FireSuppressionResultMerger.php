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

        $geminiSystems = array_values(array_filter(
            (array) ($geminiData['fire_systems'] ?? []),
            'is_array'
        ));

        $templateSystems = array_values(array_filter(
            (array) ($semantic['template']['fire_systems']['systems'] ?? []),
            'is_array'
        ));

        // The current Gemini contract stores the discovered control list in
        // template.fire_systems.systems. Use that tree as the semantic source
        // when extracted_data has not yet been populated with concrete controls.
        if ($geminiSystems === []) {
            $geminiSystems = $templateSystems;
        }

        $camelotSystems = array_values(array_filter(
            (array) ($camelotResult['extracted_data']['fire_systems'] ?? []),
            'is_array'
        ));

        $finalSystems = [];

        foreach ($geminiSystems as $index => $geminiSystem) {
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

            // Prefer concrete Gemini controls. If the Gemini semantic section
            // is empty, materialize code + criterion from the same Gemini
            // control index/pattern entries. Never read criterion from Camelot.
            $geminiControls = (array) ($geminiSystem['control_items'] ?? []);
            if ($geminiControls === [] && $templateSystem !== $geminiSystem) {
                $geminiControls = (array) ($templateSystem['control_items'] ?? []);
            }

            $finalControls = [];
            foreach ($geminiControls as $controlIndex => $geminiControl) {
                if (!is_array($geminiControl)) {
                    continue;
                }

                $code = $this->concreteCode($geminiControl);
                if ($code === '') {
                    continue;
                }

                $criterion = $this->concreteCriterion($geminiControl);
                $finalControl = [
                    'code' => $code,
                    'criterion' => $criterion,
                ];

                // Keep any semantic fields Gemini already supplied, but never
                // allow Camelot/PDF geometry to overwrite criterion.
                foreach ($geminiControl as $key => $value) {
                    if (in_array($key, ['control_code_patterns', 'control_text_patterns', 'result_patterns', 'results', 'source_pages'], true)) {
                        continue;
                    }
                    $finalControl[$key] = $value;
                }
                $finalControl['code'] = $code;
                $finalControl['criterion'] = $criterion;

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

            // If Gemini produced concrete controls in extracted_data but the
            // template list is empty, preserve them exactly.
            if ($finalControls === [] && $geminiControls !== []) {
                foreach ($geminiControls as $control) {
                    if (!is_array($control)) continue;
                    $code = $this->concreteCode($control);
                    if ($code === '') continue;
                    $control['code'] = $code;
                    $control['criterion'] = $this->concreteCriterion($control);
                    if (isset($camelotControls[$code])) {
                        $results = (array) ($camelotControls[$code]['results'] ?? []);
                        if ($results !== []) {
                            $control['results'] = $this->mergeResults($results);
                            $control['source_pages'] = $this->pagesFromResults($results);
                        }
                    }
                    $finalControls[$code] = $control;
                }
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

    private function indexControls(array $controls): array
    {
        $indexed = [];
        foreach ($controls as $control) {
            if (!is_array($control)) continue;
            $code = $this->concreteCode($control);
            if ($code !== '') {
                $indexed[$code] = $control;
            }
        }
        return $indexed;
    }

    private function concreteCode(array $control): string
    {
        foreach (['code', 'control_code', 'control_code_pattern'] as $key) {
            $value = trim((string) ($control[$key] ?? ''));
            if ($value !== '') {
                return $this->normalizeCode($value);
            }
        }

        foreach ((array) ($control['control_code_patterns'] ?? []) as $pattern) {
            $value = trim((string) $pattern);
            if ($value === '') continue;
            $code = $this->normalizeCode($value);
            if ($code !== '') return $code;
        }

        return '';
    }

    private function concreteCriterion(array $control): ?string
    {
        foreach (['criterion', 'control_text', 'text', 'description'] as $key) {
            if (array_key_exists($key, $control) && trim((string) $control[$key]) !== '') {
                return trim((string) $control[$key]);
            }
        }

        // Gemini's template contract calls this field control_text_patterns.
        // The pattern at the same control index is the criterion definition;
        // it is not extracted from PDF/Camelot cells.
        foreach ((array) ($control['control_text_patterns'] ?? []) as $pattern) {
            $value = trim((string) $pattern);
            if ($value !== '') return $value;
        }

        return null;
    }

    private function findSystem(array $systems, string $systemName): ?array
    {
        $key = $this->normalizeLabel($systemName);
        if ($key === '') return null;

        foreach ($systems as $system) {
            if (!is_array($system)) continue;
            $candidate = $this->normalizeLabel((string) ($system['system_name'] ?? $system['name'] ?? ''));
            if ($candidate === $key) return $system;
        }
        return null;
    }

    private function normalizeCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (preg_match('/\b(\d+(?:\.\d+)+)\b/u', $value, $m) === 1) return $m[1];
        return mb_strtoupper(preg_replace('/\s+/u', '', $value) ?? '', 'UTF-8');
    }

    private function normalizeLabel(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['ı'=>'i','ğ'=>'g','ü'=>'u','ş'=>'s','ö'=>'o','ç'=>'c']);
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value) ?? $value);
    }

    private function mergeResults(array $results): array
    {
        $merged = [];
        foreach ($results as $result) {
            if (!is_array($result)) continue;
            $equipmentCode = trim((string) ($result['equipment_code'] ?? ''));
            if ($equipmentCode === '') continue;
            $merged[mb_strtoupper($equipmentCode, 'UTF-8')] = $result;
        }
        return array_values($merged);
    }

    private function pagesFromResults(array $results): array
    {
        $pages = [];
        foreach ($results as $result) {
            if (!is_array($result)) continue;
            foreach ((array) ($result['source_pages'] ?? []) as $page) {
                $page = (int) $page;
                if ($page > 0) $pages[] = $page;
            }
        }
        return array_values(array_unique($pages));
    }
}
