<?php
namespace App\Services\Ai;

/**
 * V12 final analyzer orchestration layer.
 *
 * Keeps V11's final JSON contract and optionally enriches wide matrix control
 * rows with deterministic coordinate-based equipment references.
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

        $result['analyzer']['version'] = '12.0.0';
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
        if (!$coordinateControls) return $result;

        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $systemName = $this->normalizeSystemKey((string)($system['name'] ?? ''));
            foreach ($coordinateControls as $coordinateControl) {
                if ($this->normalizeSystemKey((string)($coordinateControl['system_name'] ?? '')) !== $systemName) continue;
                if (($coordinateControl['code'] ?? '') === '') continue;

                $matched = false;
                foreach ($system['control_items'] ?? [] as $controlIndex => $control) {
                    if ((string)($control['code'] ?? '') !== (string)$coordinateControl['code']) continue;
                    $system['control_items'][$controlIndex]['scope'] = 'equipment';
                    $system['control_items'][$controlIndex]['equipment_refs'] = array_values(array_unique($coordinateControl['equipment_refs'] ?? []));
                    if (!empty($coordinateControl['status'])) $system['control_items'][$controlIndex]['status'] = $coordinateControl['status'];
                    if (!empty($coordinateControl['source_pages'])) $system['control_items'][$controlIndex]['source_pages'] = array_values($coordinateControl['source_pages']);
                    $matched = true;
                    break;
                }

                if (!$matched) {
                    $system['control_items'][] = [
                        'code' => $coordinateControl['code'],
                        'description' => $coordinateControl['description'] ?? null,
                        'status' => $coordinateControl['status'] ?? null,
                        'scope' => 'equipment',
                        'equipment_refs' => array_values(array_unique($coordinateControl['equipment_refs'] ?? [])),
                        'source_pages' => array_values($coordinateControl['source_pages'] ?? [])
                    ];
                }
            }
            $result['systems'][$systemIndex] = $system;
        }

        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $refs = [];
            foreach ($system['control_items'] ?? [] as $control) {
                if (($control['status'] ?? null) !== 'UD') continue;
                foreach ($control['equipment_refs'] ?? [] as $ref) {
                    $key = $this->normalizeEquipmentCode((string)$ref);
                    if ($key !== '') $refs[$key] = true;
                }
            }
            foreach ($system['findings'] ?? [] as $finding) {
                foreach ($finding['equipment_refs'] ?? [] as $ref) {
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

    private function normalizeSystemKey(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value), 'UTF-8'));
    }

    private function normalizeEquipmentCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/u', '', $code);
        return str_replace(['–', '—', '‑'], '-', $code);
    }
}
