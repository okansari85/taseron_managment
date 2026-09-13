<?php
namespace App\Services\Ai;

/**
 * V12 final analyzer orchestration layer.
 * Coordinate data is used only to resolve real equipment-column U/UD cells.
 */
class UniversalFireSuppressionTableAnalyzerV12 extends UniversalFireSuppressionTableAnalyzerV11
{
    public function __construct(private readonly CoordinateTableAnalyzer $coordinateAnalyzer)
    {
    }

    public function analyze(array $pages, array $semantic, array $coordinatePages = []): array
    {
        $result = parent::analyze($pages, $semantic);

        if ($coordinatePages !== []) {
            $coordinateControls = $this->coordinateAnalyzer->analyze(
                $coordinatePages,
                $this->equipmentFromSystems($result['systems'] ?? [])
            );
            $result = $this->applyCoordinateControls($result, $coordinateControls);
        }

        $result['analyzer']['version'] = '12.1.0';
        return $result;
    }

    private function equipmentFromSystems(array $systems): array
    {
        $equipment = [];
        foreach ($systems as $system) {
            foreach ((array)($system['components'] ?? []) as $component) {
                if (is_array($component) && !empty($component['code'])) $equipment[] = $component;
            }
        }
        return $equipment;
    }

    private function applyCoordinateControls(array $result, array $coordinateControls): array
    {
        foreach ($coordinateControls as $coordinateControl) {
            $refs = array_values(array_unique(array_filter((array)($coordinateControl['equipment_refs'] ?? []))));
            if (!$refs || ($coordinateControl['status'] ?? null) !== 'UD') continue;

            $targetSystemIndexes = $this->systemsForEquipmentRefs($result['systems'] ?? [], $refs);
            if (!$targetSystemIndexes) continue;

            foreach ($targetSystemIndexes as $systemIndex) {
                $system = $result['systems'][$systemIndex];
                $matched = false;
                foreach ((array)($system['control_items'] ?? []) as $controlIndex => $control) {
                    if ((string)($control['code'] ?? '') !== (string)($coordinateControl['code'] ?? '')) continue;

                    // A coordinate-derived equipment mapping is authoritative for
                    // the equipment relationship. Keep a system-level control
                    // separate if the same code exists elsewhere.
                    $system['control_items'][$controlIndex]['scope'] = 'equipment';
                    $system['control_items'][$controlIndex]['equipment_refs'] = $this->refsBelongingToSystem(
                        $refs,
                        (array)($system['components'] ?? [])
                    );
                    $system['control_items'][$controlIndex]['status'] = 'UD';
                    if (!empty($coordinateControl['source_pages'])) {
                        $system['control_items'][$controlIndex]['source_pages'] = array_values($coordinateControl['source_pages']);
                    }
                    $matched = true;
                }

                if ($matched) {
                    $result['systems'][$systemIndex] = $system;
                }
            }
        }

        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $refs = [];
            foreach ((array)($system['control_items'] ?? []) as $control) {
                if (($control['status'] ?? null) !== 'UD' || ($control['scope'] ?? 'system') !== 'equipment') continue;
                foreach ((array)($control['equipment_refs'] ?? []) as $ref) {
                    $key = $this->normalizeEquipmentCode((string)$ref);
                    if ($key !== '') $refs[$key] = true;
                }
            }
            foreach ((array)($system['findings'] ?? []) as $finding) {
                foreach ((array)($finding['equipment_refs'] ?? []) as $ref) {
                    $key = $this->normalizeEquipmentCode((string)$ref);
                    if ($key !== '') $refs[$key] = true;
                }
            }

            $result['systems'][$systemIndex]['nonconforming_equipment_count'] = count($refs);
            $result['systems'][$systemIndex]['control_count'] = count($result['systems'][$systemIndex]['control_items'] ?? []);
            $result['systems'][$systemIndex]['nonconforming_count'] = count(array_filter(
                $result['systems'][$systemIndex]['control_items'] ?? [],
                fn(array $control) => ($control['status'] ?? null) === 'UD'
            ));
            $result['systems'][$systemIndex]['status'] = $this->deriveSystemStatus(
                $result['systems'][$systemIndex]['control_items'] ?? [],
                $result['systems'][$systemIndex]['findings'] ?? []
            );
        }

        $result['analyzer']['control_count'] = array_sum(array_map(
            fn(array $system) => (int)($system['control_count'] ?? 0),
            $result['systems'] ?? []
        ));
        return $result;
    }

    private function systemsForEquipmentRefs(array $systems, array $refs): array
    {
        $wanted = array_fill_keys(array_map(fn($r) => $this->normalizeEquipmentCode((string)$r), $refs), true);
        $indexes = [];
        foreach ($systems as $index => $system) {
            foreach ((array)($system['components'] ?? []) as $component) {
                $key = $this->normalizeEquipmentCode((string)($component['code'] ?? ''));
                if ($key !== '' && isset($wanted[$key])) {
                    $indexes[] = $index;
                    break;
                }
            }
        }
        return array_values(array_unique($indexes));
    }

    private function refsBelongingToSystem(array $refs, array $components): array
    {
        $allowed = [];
        foreach ($components as $component) {
            $code = (string)($component['code'] ?? '');
            if ($code !== '') $allowed[$this->normalizeEquipmentCode($code)] = $code;
        }
        $out = [];
        foreach ($refs as $ref) {
            $key = $this->normalizeEquipmentCode((string)$ref);
            if ($key !== '' && isset($allowed[$key])) $out[$key] = $allowed[$key];
        }
        return array_values($out);
    }

    private function normalizeEquipmentCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/u', '', $code);
        return str_replace(['–','—','‑'], '-', $code);
    }
}
