<?php

namespace App\Services\Ai;

class UniversalFireSuppressionTableAnalyzerV12 extends UniversalFireSuppressionTableAnalyzerV11
{
    private CoordinateTableAnalyzer $coordinateAnalyzer;

    public function __construct(CoordinateTableAnalyzer $coordinateAnalyzer)
    {
        $this->coordinateAnalyzer = $coordinateAnalyzer;
    }

    public function analyze(array $pages, array $semantic = []): array
    {
        $result = parent::analyze($pages, $semantic);
        $result = $this->removeFindingRowsFromControls($result);
        $result = $this->bindFindingEquipmentToControls($result);

        $coordinatePages = [];
        foreach ($pages as $page) {
            if (isset($page['data'])) {
                $coordinatePages[] = $page;
            }
        }

        if ($coordinatePages) {
            $coordinateControls = $this->coordinateAnalyzer->analyze(
                $coordinatePages,
                $this->equipmentFromSystems($result['systems'] ?? [])
            );
            $result = $this->applyCoordinateControls($result, $coordinateControls);
        }

        $result['analyzer']['version'] = '12.4.1';
        $result['analyzer']['fixture_mode'] = true;

        return $result;
    }

    private function removeFindingRowsFromControls(array $result): array
    {
        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $controls = [];
            foreach ($system['control_items'] ?? [] as $control) {
                $description = mb_strtolower((string)($control['description'] ?? ''), 'UTF-8');
                if (str_contains($description, 'bulgu') && !preg_match('/^\s*\d+(?:\.\d+)?\s*\)/u', $description)) {
                    continue;
                }
                $controls[] = $control;
            }
            $result['systems'][$systemIndex]['control_items'] = $controls;
        }

        return $result;
    }

    private function bindFindingEquipmentToControls(array $result): array
    {
        $rootFindings = [];
        foreach ($result['findings'] ?? [] as $finding) {
            if (isset($finding['id'])) {
                $rootFindings[(string)$finding['id']] = $finding;
            }
        }

        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $equipmentCodes = $this->equipmentCodeMap((array)($system['components'] ?? []));
            if (!$equipmentCodes) {
                continue;
            }

            $controlIndexes = [];
            foreach ($system['control_items'] ?? [] as $controlIndex => $control) {
                $code = $this->normalizeControlCode((string)($control['code'] ?? ''));
                if ($code !== '') {
                    $controlIndexes[$code] = $controlIndex;
                }
            }

            foreach ($system['findings'] ?? [] as $projection) {
                $findingId = (string)($projection['id'] ?? '');
                if ($findingId === '' || !isset($rootFindings[$findingId])) {
                    continue;
                }

                $finding = $rootFindings[$findingId];
                $description = (string)($finding['description'] ?? '');
                $refs = $this->resolveFindingEquipmentRefs(
                    $description,
                    (array)($projection['equipment_refs'] ?? []),
                    $equipmentCodes
                );

                if (!$refs) {
                    continue;
                }

                $criterionCode = $this->extractCriterionCode($description);
                if ($criterionCode === null || !isset($controlIndexes[$criterionCode])) {
                    continue;
                }

                $controlIndex = $controlIndexes[$criterionCode];
                $existingRefs = $this->resolveFindingEquipmentRefs(
                    '',
                    (array)($result['systems'][$systemIndex]['control_items'][$controlIndex]['equipment_refs'] ?? []),
                    $equipmentCodes
                );

                $result['systems'][$systemIndex]['control_items'][$controlIndex]['scope'] = 'equipment';
                $result['systems'][$systemIndex]['control_items'][$controlIndex]['equipment_refs'] = array_values(array_unique(array_merge($existingRefs, $refs)));
            }
        }

        return $result;
    }

    private function equipmentCodeMap(array $components): array
    {
        $map = [];
        foreach ($components as $component) {
            if (!is_array($component)) continue;
            $code = trim((string)($component['code'] ?? ''));
            $key = $this->normalizeEquipmentCode($code);
            if ($key !== '') {
                $map[$key] = $code;
            }
        }
        return $map;
    }

    private function resolveFindingEquipmentRefs(string $text, array $existing, array $equipmentCodes): array
    {
        $refs = [];

        foreach ($existing as $ref) {
            $key = $this->normalizeEquipmentCode((string)$ref);
            if ($key !== '' && isset($equipmentCodes[$key])) {
                $refs[$key] = $equipmentCodes[$key];
            }
        }

        if (trim($text) === '' || !$equipmentCodes) {
            return array_values($refs);
        }

        $normalizedText = strtoupper($text);
        $normalizedText = str_replace(['–', '—', '‑', '−'], '-', $normalizedText);

        // First resolve exact equipment codes. This covers YD14, YD-14,
        // YD_14 and equivalent report formatting.
        foreach (array_keys($equipmentCodes) as $normalizedCode) {
            $pattern = preg_quote($normalizedCode, '/');
            if (preg_match('/(?<![A-Z0-9])' . $pattern . '(?![A-Z0-9])/u', $normalizedText)) {
                $refs[$normalizedCode] = $equipmentCodes[$normalizedCode];
            }
        }

        // Then resolve common numeric ranges such as YD1-YD4, YD1-YD4,
        // YD 1 ile YD 4 and YD1-YD4. Only real extracted equipment codes
        // are emitted; no equipment is invented from the range.
        preg_match_all(
            '/\b(YD|HD|H|P)\s*[-_]?\s*(\d+)\s*(?:-|TO|ILE|İLE|ARASI)\s*(?:\1\s*[-_]?\s*)?(\d+)\b/iu',
            $normalizedText,
            $ranges,
            PREG_SET_ORDER
        );

        foreach ($ranges as $range) {
            $prefix = strtoupper($range[1]);
            $from = (int)$range[2];
            $to = (int)$range[3];
            if ($from > $to) [$from, $to] = [$to, $from];

            for ($number = $from; $number <= $to; $number++) {
                foreach ([$prefix . $number, $prefix . '-' . $number] as $candidate) {
                    $key = $this->normalizeEquipmentCode($candidate);
                    if (isset($equipmentCodes[$key])) {
                        $refs[$key] = $equipmentCodes[$key];
                    }
                }
            }
        }

        return array_values($refs);
    }

    private function extractCriterionCode(string $description): ?string
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*\)/u', $description, $match)) {
            return $this->normalizeControlCode($match[1]);
        }

        if (preg_match('/\b(\d+(?:\.\d+)?)\s*\)/u', $description, $match)) {
            return $this->normalizeControlCode($match[1]);
        }

        return null;
    }

    private function normalizeControlCode(string $code): string
    {
        return preg_replace('/\s+/u', '', trim($code));
    }

    private function normalizeEquipmentCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/u', '', $code);
        return str_replace(['–', '—', '‑', '−', '_'], '-', $code);
    }

    private function equipmentFromSystems(array $systems): array
    {
        $equipment = [];
        foreach ($systems as $system) {
            foreach ($system['components'] ?? [] as $component) {
                $code = trim((string)($component['code'] ?? ''));
                if ($code !== '') {
                    $equipment[] = ['code' => $code];
                }
            }
        }
        return $equipment;
    }

    private function applyCoordinateControls(array $result, array $coordinateControls): array
    {
        foreach ($coordinateControls as $control) {
            $code = $this->normalizeControlCode((string)($control['code'] ?? ''));
            $equipmentRefs = array_values(array_unique(array_filter(array_map('strval', (array)($control['equipment_refs'] ?? [])))));
            if ($code === '' || !$equipmentRefs) {
                continue;
            }

            foreach ($result['systems'] ?? [] as $systemIndex => $system) {
                $systemCodes = array_map(
                    fn ($component) => trim((string)($component['code'] ?? '')),
                    $system['components'] ?? []
                );
                $matched = array_values(array_intersect($equipmentRefs, $systemCodes));
                if (!$matched) {
                    continue;
                }

                foreach ($system['control_items'] ?? [] as $controlIndex => $item) {
                    if ($this->normalizeControlCode((string)($item['code'] ?? '')) !== $code) {
                        continue;
                    }

                    $existingRefs = (array)($result['systems'][$systemIndex]['control_items'][$controlIndex]['equipment_refs'] ?? []);
                    $result['systems'][$systemIndex]['control_items'][$controlIndex]['scope'] = 'equipment';
                    $result['systems'][$systemIndex]['control_items'][$controlIndex]['equipment_refs'] = array_values(array_unique(array_merge($existingRefs, $matched)));
                }
            }
        }

        return $result;
    }
}
