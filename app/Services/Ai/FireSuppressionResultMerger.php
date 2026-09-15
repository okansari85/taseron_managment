<?php

namespace App\Services\Ai;

/**
 * Merges Gemini semantic control metadata with Camelot matrix results.
 *
 * Gemini owns: system_name, code and criterion.
 * Camelot owns: equipment, result/results, values and source_pages.
 */
class FireSuppressionResultMerger
{
    public function merge(array $camelotResult, array $semantic): array
    {
        $geminiSystems = (array) ($semantic['extracted_data']['fire_systems'] ?? []);
        $camelotSystems = (array) ($camelotResult['extracted_data']['fire_systems'] ?? []);

        if ($geminiSystems === []) {
            return $camelotResult;
        }

        foreach ($camelotSystems as $systemIndex => &$camelotSystem) {
            if (!is_array($camelotSystem)) {
                continue;
            }

            $systemKey = $this->normalizeLabel((string) ($camelotSystem['system_name'] ?? $camelotSystem['name'] ?? ''));
            $geminiSystem = $this->findGeminiSystem($geminiSystems, $systemKey, (int) $systemIndex);
            if ($geminiSystem === null) {
                continue;
            }

            $camelotControls = [];
            foreach ((array) ($camelotSystem['control_items'] ?? []) as $control) {
                if (!is_array($control)) {
                    continue;
                }
                $code = $this->normalizeCode((string) ($control['code'] ?? ''));
                if ($code !== '') {
                    $camelotControls[$code] = $control;
                }
            }

            $finalControls = [];
            foreach ((array) ($geminiSystem['control_items'] ?? []) as $geminiControl) {
                if (!is_array($geminiControl)) {
                    continue;
                }

                $code = $this->normalizeCode((string) ($geminiControl['code'] ?? ''));
                if ($code === '') {
                    continue;
                }

                $control = $camelotControls[$code] ?? [
                    'code' => (string) ($geminiControl['code'] ?? $code),
                    'results' => [],
                    'source_pages' => [],
                ];

                $criterion = $this->clean((string) ($geminiControl['criterion'] ?? $geminiControl['description'] ?? ''));
                if ($criterion !== null) {
                    $control['criterion'] = $criterion;
                }

                if (!isset($control['results']) || !is_array($control['results'])) {
                    $control['results'] = [];
                }

                $finalControls[$code] = $control;
            }

            $camelotSystem['control_items'] = array_values($finalControls);
        }
        unset($camelotSystem);

        $camelotResult['extracted_data']['fire_systems'] = array_values($camelotSystems);
        return $camelotResult;
    }

    private function findGeminiSystem(array $systems, string $systemKey, int $index): ?array
    {
        if ($systemKey !== '') {
            foreach ($systems as $system) {
                if (!is_array($system)) {
                    continue;
                }
                $candidate = $this->normalizeLabel((string) ($system['system_name'] ?? $system['name'] ?? ''));
                if ($candidate === $systemKey) {
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
        if ($value === '') {
            return '';
        }

        if (preg_match('/\b(\d+(?:\.\d+)+)\b/u', $value, $matches) === 1) {
            return $matches[1];
        }

        return mb_strtoupper(preg_replace('/\s+/u', '', $value) ?? '', 'UTF-8');
    }

    private function normalizeLabel(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c',
        ]);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function clean(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return $value === '' ? null : $value;
    }
}
