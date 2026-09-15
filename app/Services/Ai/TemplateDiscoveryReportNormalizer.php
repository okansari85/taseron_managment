<?php

namespace App\Services\Ai;

/** Normalizes the final fire-suppression extraction into the stable report contract. */
class TemplateDiscoveryReportNormalizer
{
    public function normalize(array $semantic, ?string $fixtureId = null): array
    {
        $data = is_array($semantic['extracted_data'] ?? null)
            ? $semantic['extracted_data']
            : $semantic;

        $report = is_array($data['report'] ?? null) ? $data['report'] : [];
        $systems = is_array($data['systems'] ?? null)
            ? $data['systems']
            : (is_array($data['fire_systems'] ?? null) ? $data['fire_systems'] : []);
        $findings = is_array($data['findings'] ?? null) ? $data['findings'] : [];

        $normalizedSystems = [];
        $equipmentCount = 0;
        $controlCount = 0;

        foreach ($systems as $system) {
            if (!is_array($system)) continue;

            $name = $this->stringOrNull($system['name'] ?? $system['system_name'] ?? null);
            if ($name === null) continue;

            $category = $this->normalizeCategory($system['category'] ?? null);
            $components = $this->normalizeComponents((array) ($system['components'] ?? $system['equipment'] ?? []));
            $controls = $this->normalizeControls((array) ($system['control_items'] ?? []));

            $known = (bool) ($system['equipment_count_known'] ?? (count($components) > 0));
            $systemEquipmentCount = $known
                ? max(0, (int) ($system['equipment_count'] ?? count($components)))
                : count($components);

            $normalizedSystems[] = [
                'name' => $name,
                'category' => $category,
                'equipment_count' => $systemEquipmentCount,
                'equipment_count_known' => $known,
                'control_count' => count($controls),
                'components' => $components,
                'control_items' => $controls,
            ];

            $equipmentCount += $systemEquipmentCount;
            $controlCount += count($controls);
        }

        $normalizedFindings = $this->normalizeFindings($findings);
        $coveredCategories = array_values(array_unique(array_filter(array_map(
            fn (array $system) => $system['category'] ?? null,
            $normalizedSystems
        ))));

        return [
            'report' => [
                'report_no' => $this->stringOrNull($report['report_no'] ?? null),
                'company_name' => $this->stringOrNull($report['company_name'] ?? null),
                'control_date' => $this->dateOrNull($report['control_date'] ?? null),
                'next_control_date' => $this->dateOrNull($report['next_control_date'] ?? null),
                'overall_result' => $this->normalizeResult($report['overall_result'] ?? null),
            ],
            'covered_categories' => $coveredCategories,
            'systems' => $normalizedSystems,
            'findings' => $normalizedFindings,
            'matched_inventory_items' => (array) ($data['matched_inventory_items'] ?? []),
            'candidate_inventory_items' => (array) ($data['candidate_inventory_items'] ?? []),
            'unmatched_codes' => array_values(array_filter(array_map('strval', (array) ($data['unmatched_codes'] ?? [])))),
            'analyzer' => [
                'version' => 'template-discovery-2',
                'table_count' => $this->templateTableCount($semantic),
                'equipment_count' => $equipmentCount,
                'control_count' => $controlCount,
                'finding_count' => count($normalizedFindings),
                'fixture_mode' => $fixtureId !== null,
            ],
            'fixture_id' => $fixtureId,
        ];
    }

    private function normalizeComponents(array $components): array
    {
        $out = [];
        $seen = [];

        foreach ($components as $component) {
            if (!is_array($component)) continue;

            $code = $this->stringOrNull($component['code'] ?? null);
            $name = $this->stringOrNull($component['name'] ?? null);
            if ($code === null && $name === null) continue;

            $key = mb_strtoupper(trim((string) ($code ?? $name)), 'UTF-8');
            if ($key !== '' && isset($seen[$key])) continue;
            if ($key !== '') $seen[$key] = true;

            $out[] = [
                'code' => $code,
                'name' => $name,
                'location' => $this->stringOrNull($component['location'] ?? null),
                'brand' => $this->stringOrNull($component['brand'] ?? null),
                'model' => $this->stringOrNull($component['model'] ?? null),
                'serial_no' => $this->stringOrNull($component['serial_no'] ?? null),
                'properties' => is_array($component['properties'] ?? null) ? $component['properties'] : [],
                'source_pages' => $this->pages($component['source_pages'] ?? []),
            ];
        }

        return $out;
    }

    private function normalizeControls(array $controls): array
    {
        $out = [];
        $seen = [];

        foreach ($controls as $control) {
            if (!is_array($control)) continue;

            $code = trim((string) ($control['code'] ?? $control['control_code'] ?? ''));
            if ($code === '') continue;

            $key = $this->normalizeCode($code);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $scope = strtolower(trim((string) ($control['scope'] ?? 'system')));
            $scope = in_array($scope, ['equipment', 'system'], true) ? $scope : 'system';
            $equipment = trim((string) ($control['equipment'] ?? ''));
            if ($scope === 'system') $equipment = '';

            $results = is_array($control['results'] ?? null) ? $control['results'] : [];
            $criterion = $this->stringOrNull($control['criterion'] ?? $control['description'] ?? null);

            $out[] = [
                'code' => $code,
                'description' => $criterion,
                'criterion' => $criterion,
                'scope' => $scope,
                'equipment' => $equipment,
                'results' => $this->normalizeResults($results),
                'source_pages' => $this->pages($control['source_pages'] ?? []),
            ];
        }

        return $out;
    }

    private function normalizeResults(array $results): array
    {
        $out = [];

        foreach ($results as $key => $value) {
            if (is_array($value)) {
                // Camelot matrix result object: keep its structure intact.
                if (array_key_exists('equipment_code', $value) || array_key_exists('result', $value)) {
                    $out[] = [
                        'equipment_code' => $this->stringOrNull($value['equipment_code'] ?? $value['equipment'] ?? null),
                        'result' => $this->stringOrNull($value['result'] ?? null),
                        'value' => $value['value'] ?? null,
                        'source_pages' => $this->pages($value['source_pages'] ?? []),
                    ];
                    continue;
                }

                // Preserve ordinary nested result data instead of stringifying it.
                $out[$key] = $value;
            } elseif ($value !== null) {
                $normalizedKey = trim((string) $key);
                if ($normalizedKey !== '') $out[$normalizedKey] = trim((string) $value);
            }
        }

        return $out;
    }

    private function normalizeFindings(array $findings): array
    {
        $out = [];

        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) continue;
            $description = trim((string) ($finding['description'] ?? ''));
            if ($description === '') continue;

            $out[] = [
                'id' => $this->stringOrNull($finding['id'] ?? null) ?? 'finding-' . ($index + 1),
                'system_name' => $this->stringOrNull($finding['system_name'] ?? null),
                'description' => $description,
                'affected_equipment' => array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array) ($finding['affected_equipment'] ?? [])
                )))),
                'source_pages' => $this->pages($finding['source_pages'] ?? []),
            ];
        }

        return $out;
    }

    private function templateTableCount(array $semantic): int
    {
        $count = 0;
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? $semantic['template']['systems'] ?? []) as $system) {
            if (!is_array($system)) continue;
            $count += count((array) ($system['tables'] ?? []));
        }
        return $count;
    }

    private function pages(mixed $pages): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($page) => is_numeric($page) ? (int) $page : null,
            (array) $pages
        ), fn ($page) => $page !== null && $page > 0)));
    }

    private function normalizeCategory(mixed $category): string
    {
        $value = mb_strtolower(trim((string) $category), 'UTF-8');
        $allowed = [
            'yangin_dolabi', 'yangin_pompasi', 'hidrant', 'sprinkler',
            'su_alma_verme', 'su_deposu', 'sabit_boru_tesisati',
            'gazli_sondurme', 'diger',
        ];
        return in_array($value, $allowed, true) ? $value : 'diger';
    }

    private function normalizeCode(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? '', 'UTF-8');
    }

    private function normalizeResult(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        if ($value === '') return null;
        if (in_array($value, ['uygun', 'u', 'ok'], true)) return 'uygun';
        if (in_array($value, ['uygun_degil', 'uygun değil', 'ud', 'uygunsuz'], true)) return 'uygun_degil';
        return $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if ($value === null) return null;

        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('Y-m-d', $timestamp);
    }
}
