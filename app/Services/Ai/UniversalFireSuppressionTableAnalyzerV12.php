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

        // Some reports repeat a control code in the later findings/defects
        // section. Those rows can contain equipment codes (YD1, YD2, ...),
        // but they are findings, not additional control criteria. Canonical
        // findings already come from Gemini, so remove only the duplicated
        // equipment-specific control rows here.
        $result = $this->removeFindingRowsFromControls($result);

        if ($coordinatePages !== []) {
            $coordinateControls = $this->coordinateAnalyzer->analyze(
                $coordinatePages,
                $this->equipmentFromSystems($result['systems'] ?? [])
            );
            $result = $this->applyCoordinateControls($result, $coordinateControls);
        }

        $result['analyzer']['version'] = '12.2.0';
        return $result;
    }

    private function removeFindingRowsFromControls(array $result): array
    {
        foreach ((array)($result['systems'] ?? []) as $systemIndex => $system) {
            $components = (array)($system['components'] ?? []);
            $knownCodes = [];
            foreach ($components as $component) {
                $code = strtoupper(trim((string)($component['code'] ?? '')));
                if ($code !== '') $knownCodes[$code] = true;
            }
            if (!$knownCodes) continue;

            $controls = (array)($system['control_items'] ?? []);
            $normalCodes = [];
            foreach ($controls as $control) {
                $code = trim((string)($control['code'] ?? ''));
                $description = (string)($control['description'] ?? '');
                if ($code !== '' && !$this->containsEquipmentCode($description, $knownCodes)) {
                    $normalCodes[$code] = true;
                }
            }

            $filtered = [];
            $seen = [];
            foreach ($controls as $control) {
                $code = trim((string)($control['code'] ?? ''));
                $description = (string)($control['description'] ?? '');

                // If the same criterion code has a normal control row and a
                // later equipment-specific row, keep the normal control row.
                if ($code !== '' && isset($normalCodes[$code]) && $this->containsEquipmentCode($description, $knownCodes)) {
                    continue;
                }

                $key = $code . '|' . strtoupper(trim((string)($control['status'] ?? ''))) . '|' . trim($description);
                if ($code !== '' && isset($seen[$key])) continue;
                if ($code !== '') $seen[$key] = true;
                $filtered[] = $control;
            }

            $result['systems'][$systemIndex]['control_items'] = array_values($filtered);
            $result['systems'][$systemIndex]['control_count'] = count($filtered);
            $result['systems'][$systemIndex]['nonconforming_count'] = count(array_filter(
                $filtered,
                fn(array $control) => ($control['status'] ?? null) === 'UD'
            ));
        }

        $result['analyzer']['control_count'] = array_sum(array_map(
            fn(array $system) => (int)($system['control_count'] ?? 0),
            $result['systems'] ?? []
        ));

        return $result;
    }

    private function containsEquipmentCode(string $text, array $knownCodes): bool
    {
        if ($text === '' || !$knownCodes) return false;
        $normalized = strtoupper($text);
        foreach (array_keys($knownCodes) as $code) {
            if (preg_match('/(?<![A-Z0-9])' . preg_quote($code, '/') . '(?![A-Z0-9])/u', $normalized)) {
                return true;
            }
        }
        return false;
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
