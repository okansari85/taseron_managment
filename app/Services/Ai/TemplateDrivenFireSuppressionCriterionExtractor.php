<?php

namespace App\Services\Ai;

class TemplateDrivenFireSuppressionCriterionExtractor
{
    public function __construct(
        private TemplateDrivenFireSuppressionMatrixExtractor $matrixExtractor,
    ) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        // Camelot remains responsible for matrix/equipment/result extraction.
        // Gemini result JSON is the single source of truth for control criteria.
        $result = $this->matrixExtractor->extract($pdfPath, $semantic);

        $geminiSystems = (array) ($semantic['extracted_data']['fire_systems'] ?? []);
        if ($geminiSystems === []) {
            return $result;
        }

        $criteriaBySystem = [];
        $uniqueCriteriaByCode = [];
        $codeCounts = [];

        foreach ($geminiSystems as $system) {
            if (!is_array($system)) continue;

            $systemKey = $this->normalizeLabel((string) ($system['system_name'] ?? $system['name'] ?? ''));
            foreach ((array) ($system['control_items'] ?? []) as $control) {
                if (!is_array($control)) continue;

                $code = $this->normalizeCode((string) ($control['code'] ?? ''));
                $criterion = $this->clean((string) ($control['criterion'] ?? $control['description'] ?? ''));
                if ($code === '' || $criterion === null) continue;

                if ($systemKey !== '') {
                    $criteriaBySystem[$systemKey][$code] = $criterion;
                }

                $uniqueCriteriaByCode[$code] = $criterion;
                $codeCounts[$code] = ($codeCounts[$code] ?? 0) + 1;
            }
        }

        foreach ((array) ($result['extracted_data']['fire_systems'] ?? []) as $systemIndex => &$system) {
            if (!is_array($system)) continue;

            $systemKey = $this->normalizeLabel((string) ($system['system_name'] ?? $system['name'] ?? ''));
            $existingControls = [];

            foreach ((array) ($system['control_items'] ?? []) as $control) {
                if (!is_array($control)) continue;
                $code = $this->normalizeCode((string) ($control['code'] ?? ''));
                if ($code === '') continue;

                $control['code'] = $this->displayCode((string) ($control['code'] ?? ''), $code);
                $existingControls[$code] = $control;
            }

            // Gemini's control list is canonical: a control must not disappear
            // merely because Camelot could not locate a result cell for it.
            $canonicalControls = [];
            foreach ($this->geminiControlsForSystem($geminiSystems, $systemKey) as $geminiControl) {
                if (!is_array($geminiControl)) continue;
                $code = $this->normalizeCode((string) ($geminiControl['code'] ?? ''));
                if ($code === '') continue;

                $control = $existingControls[$code] ?? [
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

                $canonicalControls[$code] = $control;
            }

            // Keep any Camelot-only control as well, but prefer Gemini criteria
            // whenever Gemini has the same code.
            foreach ($existingControls as $code => $control) {
                if (!isset($canonicalControls[$code])) {
                    $canonicalControls[$code] = $control;
                }
            }

            // Safe global-code fallback for systems whose names differ slightly
            // between Gemini and Camelot. Only use it when the Gemini code is unique.
            foreach ($canonicalControls as $code => &$control) {
                if (!empty($control['criterion'])) continue;
                if (($codeCounts[$code] ?? 0) !== 1) continue;
                if (isset($uniqueCriteriaByCode[$code])) {
                    $control['criterion'] = $uniqueCriteriaByCode[$code];
                }
            }
            unset($control);

            $system['control_items'] = array_values($canonicalControls);
        }
        unset($system);

        return $result;
    }

    private function geminiControlsForSystem(array $systems, string $systemKey): array
    {
        if ($systemKey === '') return [];

        foreach ($systems as $system) {
            if (!is_array($system)) continue;
            $candidateKey = $this->normalizeLabel((string) ($system['system_name'] ?? $system['name'] ?? ''));
            if ($candidateKey !== $systemKey) continue;
            return array_values(array_filter((array) ($system['control_items'] ?? []), 'is_array'));
        }

        return [];
    }

    private function normalizeCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        if (preg_match('/\b(\d+(?:\.\d+)+)\b/u', $value, $matches) === 1) {
            return $matches[1];
        }

        return mb_strtoupper(preg_replace('/\s+/u', '', $value) ?? '', 'UTF-8');
    }

    private function displayCode(string $original, string $normalized): string
    {
        $original = trim($original);
        return $original !== '' ? $original : $normalized;
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
