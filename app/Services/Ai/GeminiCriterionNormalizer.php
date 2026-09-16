<?php

namespace App\Services\Ai;

/**
 * Normalizes only Gemini criterion metadata before the existing pipeline runs.
 * No PDF/Camelot data is read and no non-criterion Gemini field is changed.
 */
class GeminiCriterionNormalizer
{
    public function normalize(array $semantic): array
    {
        $data = null;
        $path = null;

        if (isset($semantic['data']['extracted_data']) && is_array($semantic['data']['extracted_data'])) {
            $data = $semantic['data']['extracted_data'];
            $path = ['data', 'extracted_data'];
        } elseif (isset($semantic['extracted_data']) && is_array($semantic['extracted_data'])) {
            $data = $semantic['extracted_data'];
            $path = ['extracted_data'];
        }

        if ($data === null) {
            return $semantic;
        }

        foreach ((array) ($data['fire_systems'] ?? []) as $systemIndex => $system) {
            if (!is_array($system)) {
                continue;
            }

            foreach ((array) ($system['control_items'] ?? []) as $controlIndex => $control) {
                if (!is_array($control)) {
                    continue;
                }

                if (!array_key_exists('criterion', $control) || trim((string) $control['criterion']) === '') {
                    $criteria = array_values(array_filter(array_map(
                        static fn ($value): string => trim((string) $value),
                        (array) ($control['control_text_patterns'] ?? [])
                    ), static fn (string $value): bool => $value !== ''));

                    if (count($criteria) === 1) {
                        $control['criterion'] = $criteria[0];
                    }
                }

                $data['fire_systems'][$systemIndex]['control_items'][$controlIndex] = $control;
            }
        }

        $target =& $semantic;
        foreach ($path as $key) {
            $target =& $target[$key];
        }
        $target = $data;

        return $semantic;
    }
}
