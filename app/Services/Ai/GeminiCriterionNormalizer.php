<?php

namespace App\Services\Ai;

/**
 * Normalizes only Gemini criterion metadata before the existing pipeline runs.
 * It does not read PDF/Camelot data and does not modify any other Gemini field.
 */
class GeminiCriterionNormalizer
{
    public function normalize(array $semantic): array
    {
        $data =& $semantic['data']['extracted_data'];
        if (!isset($semantic['data']['extracted_data']) || !is_array($data)) {
            $data =& $semantic['extracted_data'];
        }

        if (!is_array($data)) {
            return $semantic;
        }

        foreach ((array) ($data['fire_systems'] ?? []) as $systemIndex => $system) {
            if (!is_array($system)) {
                continue;
            }

            $controls = (array) ($system['control_items'] ?? []);
            if ($controls === []) {
                continue;
            }

            $normalized = [];
            foreach ($controls as $control) {
                if (!is_array($control)) {
                    $normalized[] = $control;
                    continue;
                }

                $codes = $this->expandCodes((array) ($control['control_code_patterns'] ?? []));
                $criteria = array_values(array_filter(array_map(
                    static fn ($value): string => trim((string) $value),
                    (array) ($control['control_text_patterns'] ?? [])
                ), static fn (string $value): bool => $value !== ''));

                if ($codes === [] || $criteria === []) {
                    $normalized[] = $control;
                    continue;
                }

                foreach ($codes as $index => $code) {
                    $item = $control;
                    $item['criterion'] = $criteria[$index] ?? $criteria[0];
                    $normalized[] = $item;
                }
            }

            $data['fire_systems'][$systemIndex]['control_items'] = $normalized;
        }

        return $semantic;
    }

    private function expandCodes(array $patterns): array
    {
        $codes = [];

        foreach ($patterns as $pattern) {
            $value = trim(str_replace('\\.', '.', (string) $pattern));
            $value = preg_replace('/\s+/u', '', $value) ?? $value;

            if (preg_match('/^5\.\[(\d+)-(\d+)\]$/', $value, $match) === 1) {
                for ($n = (int) $match[1]; $n <= (int) $match[2]; $n++) {
                    $codes[] = '5.' . $n;
                }
                continue;
            }

            if (preg_match('/^5\.\(([^)]+)\)$/', $value, $match) === 1) {
                foreach (explode('|', $match[1]) as $part) {
                    if (ctype_digit(trim($part))) {
                        $codes[] = '5.' . (int) trim($part);
                    }
                }
                continue;
            }

            if (preg_match('/^5\.\d+$/', $value) === 1) {
                $codes[] = $value;
            }
        }

        return array_values(array_unique($codes));
    }
}
