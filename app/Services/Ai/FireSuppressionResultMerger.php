<?php

namespace App\Services\Ai;

/**
 * Gemini owns system/code/criterion/findings/overall_result.
 * Camelot owns equipment and matrix results.
 */
class FireSuppressionResultMerger
{
    public function merge(array $camelotResult, array $semantic): array
    {
        $geminiData = (array) ($semantic['data']['extracted_data']
            ?? $semantic['extracted_data']
            ?? []);
        $geminiSystems = (array) ($geminiData['fire_systems'] ?? []);
        $camelotSystems = (array) ($camelotResult['extracted_data']['fire_systems'] ?? []);

        foreach ($camelotSystems as $index => &$camelotSystem) {
            if (!is_array($camelotSystem)) continue;

            $systemKey = $this->normalizeLabel((string) ($camelotSystem['system_name'] ?? $camelotSystem['name'] ?? ''));
            $geminiSystem = $this->findSystem($geminiSystems, $systemKey, (int) $index);
            if ($geminiSystem === null) continue;

            $camelotControls = [];
            foreach ((array) ($camelotSystem['control_items'] ?? []) as $control) {
                if (!is_array($control)) continue;
                $code = $this->normalizeCode((string) ($control['code'] ?? ''));
                if ($code !== '') $camelotControls[$code] = $control;
            }

            $finalControls = [];
            foreach ((array) ($geminiSystem['control_items'] ?? []) as $geminiControl) {
                if (!is_array($geminiControl)) continue;
                $code = $this->normalizeCode((string) ($geminiControl['code'] ?? ''));
                if ($code === '') continue;

                $control = $camelotControls[$code] ?? [
                    'code' => (string) ($geminiControl['code'] ?? $code),
                    'results' => [],
                    'source_pages' => [],
                ];

                $criterion = $this->clean((string) ($geminiControl['criterion'] ?? $geminiControl['description'] ?? ''));
                if ($criterion !== null) $control['criterion'] = $criterion;
                if (!isset($control['results']) || !is_array($control['results'])) $control['results'] = [];

                $finalControls[$code] = $control;
            }

            $camelotSystem['control_items'] = array_values($finalControls);
        }
        unset($camelotSystem);

        $camelotResult['extracted_data']['fire_systems'] = array_values($camelotSystems);

        if (array_key_exists('findings', $geminiData)) {
            $camelotResult['extracted_data']['findings'] = (array) $geminiData['findings'];
        }
        if (array_key_exists('overall_result', $geminiData)) {
            $camelotResult['extracted_data']['overall_result'] = $geminiData['overall_result'];
            $camelotResult['extracted_data']['report']['overall_result'] = $geminiData['overall_result'];
        }

        return $camelotResult;
    }

    private function findSystem(array $systems, string $key, int $index): ?array
    {
        if ($key !== '') {
            foreach ($systems as $system) {
                if (!is_array($system)) continue;
                if ($this->normalizeLabel((string) ($system['system_name'] ?? $system['name'] ?? '')) === $key) {
                    return $system;
                }
            }
        }

        $fallback = $systems[$index] ?? null;
        return is_array($fallback) ? $fallback : null;
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
        $value = strtr($value, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c']);
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value) ?? $value);
    }

    private function clean(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return $value === '' ? null : $value;
    }
}
