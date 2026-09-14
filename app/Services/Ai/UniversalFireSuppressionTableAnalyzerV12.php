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

        $result['analyzer']['version'] = '12.4.0';
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
            $equipmentCodes = [];
            foreach ($system['components'] ?? [] as $component) {
                $code = trim((string)($component['code'] ?? ''));
                if ($code !== '') {
                    $equipmentCodes[mb_strtoupper($code, 'UTF-8')] = $code;
                }
            }

            if (!$equipmentCodes) {
                continue;
            }

            $controlIndexes = [];
            foreach ($system['control_items'] ?? [] as $controlIndex => $control) {
                $code = trim((string)($control['code'] ?? ''));
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
                $refs = [];

                foreach ((array)($projection['equipment_refs'] ?? []) as $ref) {
                    $key = mb_strtoupper(trim((string)$ref), 'UTF-8');
                    if (isset($equipmentCodes[$key])) {
                        $refs[] = $equipmentCodes[$key];
                    }
                }

                preg_match_all('/\b[A-Za-zÇĞİÖŞÜçğıöşü0-9]+[-_]?[A-Za-zÇĞİÖŞÜçğıöşü0-9]*\b/u', $description, $matches);
                foreach ($matches[0] ?? [] as $token) {
                    $key = mb_strtoupper(trim($token), 'UTF-8');
                    if (isset($equipmentCodes[$key])) {
                        $refs[] = $equipmentCodes[$key];
                    }
                }

                $refs = array_values(array_unique($refs));
                if (!$refs) {
                    continue;
                }

                $criterionCode = null;
                if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*\)/u', $description, $m)) {
                    $criterionCode = $m[1];
                } elseif (preg_match('/\b(\d+(?:\.\d+)?)\s*\)/u', $description, $m)) {
                    $criterionCode = $m[1];
                }

                if ($criterionCode !== null && isset($controlIndexes[$criterionCode])) {
                    $controlIndex = $controlIndexes[$criterionCode];
                    $existingRefs = (array)($result['systems'][$systemIndex]['control_items'][$controlIndex]['equipment_refs'] ?? []);
                    $result['systems'][$systemIndex]['control_items'][$controlIndex]['scope'] = 'equipment';
                    $result['systems'][$systemIndex]['control_items'][$controlIndex]['equipment_refs'] = array_values(array_unique(array_merge($existingRefs, $refs)));
                }
            }
        }

        return $result;
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
            $code = trim((string)($control['code'] ?? ''));
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
                    if (trim((string)($item['code'] ?? '')) !== $code) {
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
