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
        $result = $this->moveFindingEquipmentToRoot($result);

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

        $result['analyzer']['version'] = '12.5.0';
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

    /**
     * Findings are independent of controls. The finding itself owns the
     * equipment affected by that finding.
     *
     * Explicit equipment references are resolved against extracted equipment.
     * If a finding is clearly system-wide and names a system, all equipment
     * belonging to that physical system is attached. Report-level findings
     * without a system remain unbound.
     */
    private function moveFindingEquipmentToRoot(array $result): array
    {
        $systems = (array)($result['systems'] ?? []);
        $systemEquipment = [];
        foreach ($systems as $system) {
            if (!is_array($system)) continue;
            $name = $this->normalizeKey((string)($system['name'] ?? ''));
            if ($name === '') continue;
            $systemEquipment[$name] = array_values(array_filter(array_map(
                fn ($component) => trim((string)($component['code'] ?? '')),
                (array)($system['components'] ?? [])
            )));
        }

        $projectionRefs = [];
        foreach ($systems as $system) {
            foreach ((array)($system['findings'] ?? []) as $projection) {
                $id = trim((string)($projection['id'] ?? ''));
                if ($id === '') continue;
                $projectionRefs[$id] = array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array)($projection['equipment_refs'] ?? [])
                ))));
            }
        }

        foreach ($result['findings'] ?? [] as $index => $finding) {
            if (!is_array($finding)) continue;
            $id = trim((string)($finding['id'] ?? ''));
            $description = trim((string)($finding['description'] ?? ''));
            $refs = $projectionRefs[$id] ?? [];

            $systemName = $this->normalizeKey((string)($finding['system_name'] ?? ''));
            if (!$refs && $systemName !== '') {
                foreach ($systemEquipment as $knownSystem => $equipment) {
                    if ($knownSystem === $systemName || str_contains($knownSystem, $systemName) || str_contains($systemName, $knownSystem)) {
                        if ($this->isSystemWideFinding($description)) {
                            $refs = $equipment;
                        }
                        break;
                    }
                }
            }

            $result['findings'][$index]['affected_equipment'] = array_values(array_unique($refs));
            unset($result['findings'][$index]['equipment_refs']);
            unset($result['findings'][$index]['_equipment_refs']);
        }

        // System finding projections are no longer a second relationship layer.
        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $result['systems'][$systemIndex]['findings'] = [];
        }

        return $result;
    }

    private function isSystemWideFinding(string $description): bool
    {
        $text = mb_strtolower(trim($description), 'UTF-8');
        if ($text === '') return false;

        // Criteria describing the presence/availability of something on the
        // system are treated as applying to every equipment item in that system.
        $patterns = [
            'olmalıdır',
            'olmalıdır.',
            'bulunmalıdır',
            'bulunmalıdır.',
            'mevcut olmalıdır',
            'bulundurulmalıdır',
            'bulundurulmalıdır.',
            'olması gerekmektedir',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) return true;
        }

        return false;
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
            $status = $this->normalizeControlStatus($control['status'] ?? null);
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
                if (!$matched) continue;

                foreach ($system['control_items'] ?? [] as $controlIndex => $item) {
                    if ($this->normalizeControlCode((string)($item['code'] ?? '')) !== $code) continue;
                    if ($status !== null && $this->normalizeControlStatus($item['status'] ?? null) !== $status) continue;

                    $existingRefs = (array)($result['systems'][$systemIndex]['control_items'][$controlIndex]['equipment_refs'] ?? []);
                    $result['systems'][$systemIndex]['control_items'][$controlIndex]['scope'] = 'equipment';
                    $result['systems'][$systemIndex]['control_items'][$controlIndex]['equipment_refs'] = array_values(array_unique(array_merge($existingRefs, $matched)));
                }
            }
        }

        return $result;
    }

    private function normalizeControlStatus(mixed $status): ?string
    {
        $status = strtoupper(trim((string)$status));
        $status = str_replace(['.', ' ', '_', '-'], '', $status);
        if ($status === 'UD') return 'UD';
        if ($status === 'U') return 'U';
        if ($status === 'N') return 'N';
        if ($status === 'GD') return 'GD';
        return $status !== '' ? $status : null;
    }

    private function normalizeControlCode(string $code): string
    {
        return preg_replace('/\s+/u', '', trim($code));
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value);
    }
}
