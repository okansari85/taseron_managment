<?php

namespace App\Services\Ai;

/**
 * Final authority split:
 *
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

        // Current Template Discovery keeps the discovered system tree under
        // template.fire_systems.systems. Do not drop it just because
        // extracted_data intentionally contains findings only.
        $geminiSystems = array_values(array_filter(
            (array) ($geminiData['fire_systems'] ?? []),
            'is_array'
        ));

        if ($geminiSystems === []) {
            $geminiSystems = array_values(array_filter(
                (array) ($semantic['template']['fire_systems']['systems'] ?? []),
                'is_array'
            ));
        }

        $camelotSystems = array_values(array_filter(
            (array) ($camelotResult['extracted_data']['fire_systems'] ?? []),
            'is_array'
        ));

        $finalSystems = [];

        foreach ($geminiSystems as $geminiSystem) {
            $systemName = (string) ($geminiSystem['system_name'] ?? $geminiSystem['name'] ?? '');
            if ($systemName === '') {
                continue;
            }

            $camelotSystem = $this->findSystem($camelotSystems, $systemName);
            $camelotEquipment = $camelotSystem !== null
                ? (array) ($camelotSystem['equipment'] ?? [])
                : [];

            $camelotControls = [];
            if ($camelotSystem !== null) {
                foreach ((array) ($camelotSystem['control_items'] ?? []) as $camelotControl) {
                    if (!is_array($camelotControl)) {
                        continue;
                    }

                    $code = $this->normalizeCode((string) ($camelotControl['code'] ?? ''));
                    if ($code !== '') {
                        $camelotControls[$code] = $camelotControl;
                    }
                }
            }

            $finalControls = [];
            foreach ((array) ($geminiSystem['control_items'] ?? []) as $geminiControl) {
                if (!is_array($geminiControl)) {
                    continue;
                }

                $code = $this->normalizeCode((string) ($geminiControl['code'] ?? ''));
                if ($code === '') {
                    // Template-discovery control items currently carry code
                    // patterns rather than a concrete code. Keep those
                    // controls out of the final semantic tree until Gemini
                    // provides the concrete semantic control payload.
                    continue;
                }

                $finalControl = $geminiControl;
                $finalControl['code'] = (string) ($geminiControl['code'] ?? $code);

                $camelotControl = $camelotControls[$code] ?? null;
                if (is_array($camelotControl)) {
                    $matrixResults = (array) ($camelotControl['results'] ?? []);
                    if ($matrixResults !== []) {
                        $finalControl['results'] = $this->mergeResults($matrixResults);
                        $pages = $this->pagesFromResults($matrixResults);
                        if ($pages !== []) {
                            $finalControl['source_pages'] = $pages;
                        }
                    }
                }

                if (array_key_exists('criterion', $geminiControl)) {
                    $finalControl['criterion'] = $geminiControl['criterion'];
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
        }

        return $final;
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

        if (preg_match('/\b(\d+(?:\.\d+)+)\b/u', $value, $m) === 1) {
            return $m[1];
        }

        return mb_strtoupper(
            preg_replace('/\s+/u', '', $value) ?? '',
            'UTF-8'
        );
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

            $key = mb_strtoupper($equipmentCode, 'UTF-8');
            $merged[$key] = $result;
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
