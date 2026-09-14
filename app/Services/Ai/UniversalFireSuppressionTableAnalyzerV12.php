<?php

namespace App\Services\Ai;

/**
 * Final fire-suppression report normalizer.
 *
 * Control items and findings are intentionally independent datasets.
 * - control_items: control criterion + observed status/equipment from the matrix
 * - findings: semantic findings + affected equipment
 * - no finding -> control binding
 * - no equipment status calculation
 * - no nonconforming counts
 */
class UniversalFireSuppressionTableAnalyzerV12 extends UniversalFireSuppressionTableAnalyzerV10
{
    private CoordinateTableAnalyzer $coordinateAnalyzer;

    public function __construct(CoordinateTableAnalyzer $coordinateAnalyzer)
    {
        $this->coordinateAnalyzer = $coordinateAnalyzer;
    }

    public function analyze(array $pages, array $semantic = []): array
    {
        $base = parent::analyze($pages, $semantic);

        $systems = [];
        foreach ((array)($base['systems'] ?? []) as $system) {
            if (!is_array($system)) continue;

            $name = trim((string)($system['name'] ?? ''));
            if ($name === '') continue;

            $category = trim((string)($system['category'] ?? '')) ?: 'diger';
            $components = $this->cleanComponents((array)($system['components'] ?? []));
            $controls = $this->cleanControls((array)($system['control_items'] ?? []));

            $systems[] = [
                'name' => $name,
                'category' => $category,
                'equipment_count' => count($components),
                'equipment_count_known' => count($components) > 0,
                'control_count' => count($controls),
                'components' => $components,
                'control_items' => $controls,
            ];
        }

        $coordinatePages = array_values(array_filter(
            $pages,
            fn($page) => is_array($page) && isset($page['data'])
        ));

        if ($coordinatePages) {
            $coordinateControls = $this->coordinateAnalyzer->analyze(
                $coordinatePages,
                $this->equipmentFromSystems($systems)
            );
            $systems = $this->applyCoordinateControls($systems, $coordinateControls);
        }

        $systems = $this->groupControlsByCode($systems);

        $findings = $this->normalizeFindings(
            (array)($base['findings'] ?? []),
            $systems
        );

        $report = (array)($semantic['report'] ?? []);

        return [
            'report' => [
                'report_no' => $report['report_no'] ?? null,
                'company_name' => $report['company_name'] ?? null,
                'control_date' => $report['control_date'] ?? null,
                'next_control_date' => $report['next_control_date'] ?? null,
                'overall_result' => $report['overall_result'] ?? null,
            ],
            'covered_categories' => array_values(array_unique(array_filter(
                array_map(fn(array $system) => $system['category'] ?? null, $systems)
            ))),
            'systems' => $systems,
            'findings' => $findings,
            'matched_inventory_items' => (array)($base['matched_inventory_items'] ?? []),
            'candidate_inventory_items' => (array)($base['candidate_inventory_items'] ?? []),
            'unmatched_codes' => (array)($base['unmatched_codes'] ?? []),
            'analyzer' => [
                'version' => '12.10.1',
                'table_count' => (int)($base['analyzer']['table_count'] ?? 0),
                'equipment_count' => array_sum(array_map(
                    fn(array $system) => (int)($system['equipment_count'] ?? 0),
                    $systems
                )),
                'control_count' => array_sum(array_map(
                    fn(array $system) => (int)($system['control_count'] ?? 0),
                    $systems
                )),
                'finding_count' => count($findings),
            ],
        ];
    }

    private function cleanComponents(array $components): array
    {
        $out = [];
        foreach ($components as $component) {
            if (!is_array($component)) continue;
            $code = trim((string)($component['code'] ?? ''));
            if ($code === '') continue;

            $key = $this->normalizeEquipmentCode($code);
            $out[$key] = [
                'code' => $code,
                'name' => $component['name'] ?? null,
                'location' => $component['location'] ?? $component['location_note'] ?? null,
                'brand' => $component['brand'] ?? null,
                'model' => $component['model'] ?? null,
                'serial_no' => $component['serial_no'] ?? $component['serial'] ?? null,
                'properties' => (array)($component['properties'] ?? []),
                'source_pages' => array_values(array_unique(array_map('intval', (array)($component['source_pages'] ?? [])))),
            ];
        }
        return array_values($out);
    }

    private function cleanControls(array $controls): array
    {
        $out = [];
        foreach ($controls as $control) {
            if (!is_array($control)) continue;
            $description = trim((string)($control['description'] ?? ''));
            if ($this->looksLikeFindingRow($description)) continue;

            $code = $this->normalizeControlCode((string)($control['code'] ?? ''));
            if ($code === '') continue;

            $out[] = [
                'code' => $code,
                'description' => $description !== '' ? $description : null,
                'status' => $this->normalizeControlStatus($control['status'] ?? null),
                'equipment_refs' => $this->normalizeEquipmentRefs((array)($control['equipment_refs'] ?? [])),
                'source_pages' => array_values(array_unique(array_map('intval', (array)($control['source_pages'] ?? [])))),
            ];
        }
        return $out;
    }

    private function groupControlsByCode(array $systems): array
    {
        foreach ($systems as $systemIndex => $system) {
            $grouped = [];
            foreach ((array)($system['control_items'] ?? []) as $control) {
                if (!is_array($control)) continue;
                $code = $this->normalizeControlCode((string)($control['code'] ?? ''));
                if ($code === '') continue;

                if (!isset($grouped[$code])) {
                    $grouped[$code] = [
                        'code' => $code,
                        'description' => $control['description'] ?? null,
                        'results' => [],
                        'source_pages' => [],
                    ];
                }

                if (($grouped[$code]['description'] ?? null) === null && !empty($control['description'])) {
                    $grouped[$code]['description'] = $control['description'];
                }

                $status = $this->normalizeControlStatus($control['status'] ?? null);
                if ($status !== null) {
                    $refs = $this->normalizeEquipmentRefs((array)($control['equipment_refs'] ?? []));
                    $grouped[$code]['results'][$status] ??= [];
                    $grouped[$code]['results'][$status] = array_values(array_unique(array_merge(
                        $grouped[$code]['results'][$status],
                        $refs
                    )));
                }

                $grouped[$code]['source_pages'] = array_values(array_unique(array_merge(
                    $grouped[$code]['source_pages'],
                    array_map('intval', (array)($control['source_pages'] ?? []))
                )));
            }

            $systems[$systemIndex]['control_items'] = array_values($grouped);
            $systems[$systemIndex]['control_count'] = count($systems[$systemIndex]['control_items']);
        }
        return $systems;
    }

    private function applyCoordinateControls(array $systems, array $coordinateControls): array
    {
        foreach ($coordinateControls as $control) {
            if (!is_array($control)) continue;
            $code = $this->normalizeControlCode((string)($control['code'] ?? ''));
            $status = $this->normalizeControlStatus($control['status'] ?? null);
            $refs = $this->normalizeEquipmentRefs((array)($control['equipment_refs'] ?? []));
            if ($code === '' || $status === null || !$refs) continue;

            foreach ($systems as $systemIndex => $system) {
                $systemCodes = [];
                foreach ((array)($system['components'] ?? []) as $component) {
                    $raw = trim((string)($component['code'] ?? ''));
                    if ($raw !== '') $systemCodes[$this->normalizeEquipmentCode($raw)] = $raw;
                }

                $matched = [];
                foreach ($refs as $ref) {
                    $key = $this->normalizeEquipmentCode($ref);
                    if (isset($systemCodes[$key])) $matched[$key] = $systemCodes[$key];
                }
                if (!$matched) continue;

                foreach ($systems[$systemIndex]['control_items'] ?? [] as $controlIndex => $item) {
                    if ($this->normalizeControlCode((string)($item['code'] ?? '')) !== $code) continue;
                    $systems[$systemIndex]['control_items'][$controlIndex]['results'][$status] ??= [];
                    $systems[$systemIndex]['control_items'][$controlIndex]['results'][$status] = array_values(array_unique(array_merge(
                        $systems[$systemIndex]['control_items'][$controlIndex]['results'][$status],
                        array_values($matched)
                    )));
                }
            }
        }
        return $systems;
    }

    private function normalizeFindings(array $findings, array $systems): array
    {
        $equipmentBySystem = [];
        foreach ($systems as $system) {
            $systemKey = $this->normalizeKey((string)($system['name'] ?? ''));
            if ($systemKey === '') continue;
            $equipmentBySystem[$systemKey] = array_values(array_filter(array_map(
                fn(array $component) => trim((string)($component['code'] ?? '')),
                (array)($system['components'] ?? [])
            )));
        }

        $knownEquipment = [];
        foreach ($equipmentBySystem as $equipment) {
            foreach ($equipment as $code) {
                $knownEquipment[$this->normalizeEquipmentCode($code)] = $code;
            }
        }

        $out = [];
        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) continue;
            $description = trim((string)($finding['description'] ?? ''));
            if ($description === '') continue;

            $id = trim((string)($finding['id'] ?? '')) ?: 'finding-' . ($index + 1);
            $systemName = trim((string)($finding['system_name'] ?? ''));
            $systemKey = $this->normalizeKey($systemName);

            $refs = [];
            foreach (array_merge(
                (array)($finding['affected_equipment'] ?? []),
                (array)($finding['equipment_refs'] ?? []),
                (array)($finding['_equipment_refs'] ?? [])
            ) as $ref) {
                $key = $this->normalizeEquipmentCode((string)$ref);
                if ($key !== '' && isset($knownEquipment[$key])) $refs[$key] = $knownEquipment[$key];
            }

            $normalizedText = strtoupper(str_replace(['–', '—', '‑', '−'], '-', $description));
            foreach ($knownEquipment as $key => $code) {
                if (preg_match('/(?<![A-Z0-9])' . preg_quote($key, '/') . '(?![A-Z0-9])/u', $normalizedText)) {
                    $refs[$key] = $code;
                }
            }

            if (!$refs && $systemKey !== '' && $this->isSystemWideFinding($description)) {
                foreach ($equipmentBySystem as $knownSystem => $equipment) {
                    if ($knownSystem === $systemKey || str_contains($knownSystem, $systemKey) || str_contains($systemKey, $knownSystem)) {
                        foreach ($equipment as $code) $refs[$this->normalizeEquipmentCode($code)] = $code;
                        break;
                    }
                }
            }

            $out[] = [
                'id' => $id,
                'system_name' => $systemName !== '' ? $systemName : null,
                'description' => $description,
                'affected_equipment' => array_values($refs),
                'source_pages' => array_values(array_unique(array_map('intval', (array)($finding['source_pages'] ?? [])))),
            ];
        }
        return $out;
    }

    private function isSystemWideFinding(string $description): bool
    {
        $text = mb_strtolower($description, 'UTF-8');
        foreach (['olmalıdır', 'bulunmalıdır', 'mevcut olmalıdır', 'bulundurulmalıdır', 'olması gerekmektedir'] as $phrase) {
            if (str_contains($text, $phrase)) return true;
        }
        return false;
    }

    private function looksLikeFindingRow(string $description): bool
    {
        $text = mb_strtolower(trim($description), 'UTF-8');
        return str_contains($text, 'bulgu') && !preg_match('/^\s*\d+(?:\.\d+)?\s*\)/u', $text);
    }

    private function equipmentFromSystems(array $systems): array
    {
        $equipment = [];
        foreach ($systems as $system) {
            foreach ((array)($system['components'] ?? []) as $component) {
                $code = trim((string)($component['code'] ?? ''));
                if ($code !== '') $equipment[] = ['code' => $code];
            }
        }
        return $equipment;
    }

    private function normalizeEquipmentRefs(array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            $ref = trim((string)$ref);
            if ($ref === '') continue;
            $key = $this->normalizeEquipmentCode($ref);
            if ($key !== '') $out[$key] = $ref;
        }
        return array_values($out);
    }

    private function normalizeControlStatus(mixed $status): ?string
    {
        $value = strtoupper(trim((string)$status));
        $value = str_replace(['.', ' ', '_', '-'], '', $value);
        return $value !== '' ? $value : null;
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

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value);
    }
}
