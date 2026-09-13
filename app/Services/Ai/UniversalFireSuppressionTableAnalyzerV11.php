<?php
namespace App\Services\Ai;

/**
 * Final output normalizer for universal fire-suppression report analysis.
 *
 * Rules:
 * - components contain equipment facts only; never status/relationship refs
 * - control_items own equipment_refs
 * - root findings are canonical and never expose equipment_refs
 * - systems[].findings contain only id + equipment_refs
 */
class UniversalFireSuppressionTableAnalyzerV11 extends UniversalFireSuppressionTableAnalyzerV10
{
    public function analyze(array $pages, array $semantic): array
    {
        $result = parent::analyze($pages, $semantic);

        $equipment = $this->cleanEquipment((array)($result['equipment'] ?? []));
        $equipmentCodes = $this->normalizedEquipmentCodes($equipment);

        $findings = $this->normalizeCanonicalFindings((array)($result['findings'] ?? []), $equipmentCodes);

        $systems = [];
        foreach ((array)($result['systems'] ?? []) as $system) {
            if (!is_array($system)) continue;

            $system['components'] = $this->cleanComponents((array)($system['components'] ?? []), $equipmentCodes);
            $system['control_items'] = $this->cleanControls((array)($system['control_items'] ?? []), $equipmentCodes);

            $systemFindingIds = [];
            foreach ($findings as $finding) {
                if ($this->sameSystemForFinding($finding, $system)) {
                    $systemFindingIds[] = [
                        'id' => $finding['id'],
                        'equipment_refs' => array_values($finding['_equipment_refs'] ?? []),
                    ];
                }
            }
            $system['findings'] = $systemFindingIds;

            $nonconformingRefs = [];
            foreach ($system['control_items'] as $control) {
                if (($control['status'] ?? null) !== 'UD') continue;
                foreach ($control['equipment_refs'] ?? [] as $ref) {
                    $key = $this->normalizeEquipmentCode((string)$ref);
                    if ($key !== '') $nonconformingRefs[$key] = $equipmentCodes[$key] ?? $ref;
                }
            }
            foreach ($system['findings'] as $finding) {
                foreach ($finding['equipment_refs'] ?? [] as $ref) {
                    $key = $this->normalizeEquipmentCode((string)$ref);
                    if ($key !== '') $nonconformingRefs[$key] = $equipmentCodes[$key] ?? $ref;
                }
            }

            $system['equipment_count'] = count($system['components']);
            $system['equipment_count_known'] = count($system['components']) > 0;
            $system['nonconforming_equipment_count'] = count($nonconformingRefs);
            $system['control_count'] = count($system['control_items']);
            $system['nonconforming_count'] = count(array_filter(
                $system['control_items'],
                fn(array $control) => ($control['status'] ?? null) === 'UD'
            ));
            $system['status'] = $this->deriveSystemStatus($system['control_items'], $system['findings']);

            $systems[] = $system;
        }

        foreach ($findings as &$finding) {
            unset($finding['equipment_refs'], $finding['_equipment_refs']);
        }
        unset($finding);

        $result['report'] = $this->cleanReport((array)($result['report'] ?? []), (array)($semantic['report'] ?? []));
        $result['covered_categories'] = array_values(array_unique(array_filter(
            array_map(fn(array $system) => $system['category'] ?? null, $systems)
        )));
        $result['systems'] = $systems;
        $result['findings'] = $findings;

        unset($result['equipment'], $result['control_matrix'], $result['equipment_matrix'], $result['tables']);

        $result['analyzer'] = [
            'version' => '11.2.1',
            'table_count' => (int)($result['analyzer']['table_count'] ?? 0),
            'equipment_count' => count($equipment),
            'control_count' => array_sum(array_map(fn(array $system) => (int)($system['control_count'] ?? 0), $systems)),
            'finding_count' => count($findings),
        ];

        return $result;
    }

    private function cleanReport(array $report, array $semantic): array
    {
        return [
            'report_no' => $report['report_no'] ?? $semantic['report_no'] ?? null,
            'company_name' => $report['company_name'] ?? $semantic['company_name'] ?? null,
            'control_date' => $report['control_date'] ?? $semantic['control_date'] ?? null,
            'next_control_date' => $report['next_control_date'] ?? $semantic['next_control_date'] ?? null,
            'overall_result' => $report['overall_result'] ?? $semantic['overall_result'] ?? null,
        ];
    }

    private function cleanEquipment(array $equipment): array
    {
        $out = [];
        foreach ($equipment as $item) {
            if (!is_array($item)) continue;
            $code = trim((string)($item['code'] ?? ''));
            if ($code === '') continue;
            $out[$this->normalizeEquipmentCode($code)] = [
                'code' => $code,
                'name' => $item['name'] ?? null,
                'location' => $item['location_note'] ?? $item['location'] ?? null,
                'brand' => $item['brand'] ?? null,
                'model' => $item['model'] ?? null,
                'serial_no' => $item['serial_no'] ?? $item['serial'] ?? null,
                'properties' => (array)($item['properties'] ?? []),
                'source_pages' => array_values($item['source_pages'] ?? []),
            ];
        }
        return array_values($out);
    }

    private function cleanComponents(array $components, array $equipmentCodes): array
    {
        $out = [];
        foreach ($components as $item) {
            if (!is_array($item)) continue;
            $code = trim((string)($item['code'] ?? ''));
            if ($code === '') continue;
            $key = $this->normalizeEquipmentCode($code);
            $out[$key] = [
                'code' => $equipmentCodes[$key] ?? $code,
                'name' => $item['name'] ?? null,
                'location' => $item['location'] ?? $item['location_note'] ?? null,
                'brand' => $item['brand'] ?? null,
                'model' => $item['model'] ?? null,
                'serial_no' => $item['serial_no'] ?? $item['serial'] ?? null,
                'properties' => (array)($item['properties'] ?? []),
                'source_pages' => array_values($item['source_pages'] ?? []),
            ];
        }
        return array_values($out);
    }

    private function cleanControls(array $controls, array $equipmentCodes): array
    {
        $out = [];
        foreach ($controls as $control) {
            if (!is_array($control)) continue;
            $refs = $this->resolveEquipmentRefs((string)($control['description'] ?? ''), (array)($control['equipment_refs'] ?? []), $equipmentCodes);
            $scope = (($control['scope'] ?? 'system') === 'equipment' && $refs) ? 'equipment' : 'system';
            $out[] = [
                'code' => $control['code'] ?? null,
                'description' => $control['description'] ?? null,
                'status' => $this->normalizeControlStatus($control['status'] ?? null),
                'scope' => $scope,
                'equipment_refs' => $scope === 'equipment' ? $refs : [],
                'source_pages' => array_values($control['source_pages'] ?? []),
            ];
        }
        return $out;
    }

    private function normalizeCanonicalFindings(array $findings, array $equipmentCodes): array
    {
        $out = [];
        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) continue;
            $description = trim((string)($finding['description'] ?? ''));
            if ($description === '') continue;
            $id = trim((string)($finding['id'] ?? '')) ?: 'finding-' . ($index + 1);
            $refs = $this->resolveEquipmentRefs($description, (array)($finding['equipment_refs'] ?? []), $equipmentCodes);
            $out[] = [
                'id' => $id,
                'system_name' => $finding['system_name'] ?? null,
                'description' => $description,
                '_equipment_refs' => $refs,
                'source_pages' => array_values($finding['source_pages'] ?? []),
            ];
        }
        return $out;
    }

    private function sameSystemForFinding(array $finding, array $system): bool
    {
        $findingName = $this->normalizeKey((string)($finding['system_name'] ?? ''));
        $systemName = $this->normalizeKey((string)($system['name'] ?? ''));
        if ($findingName === '' || $systemName === '') return false;
        return $findingName === $systemName || str_contains($findingName, $systemName) || str_contains($systemName, $findingName);
    }

    protected function deriveSystemStatus(array $controls, array $findings): string
    {
        foreach ($controls as $control) if (($control['status'] ?? null) === 'UD') return 'uygun_degil';
        if ($findings) return 'uygun_degil';
        return $controls ? 'uygun' : 'belirtilmemis';
    }

    private function normalizeControlStatus(mixed $status): ?string
    {
        $status = strtoupper(trim((string)$status));
        $status = str_replace(['.', ' ', '_', '-'], '', $status);
        if ($status === 'UD') return 'UD';
        if ($status === 'U') return 'U';
        if ($status === 'N') return 'N';
        return $status !== '' ? $status : null;
    }

    private function normalizedEquipmentCodes(array $equipment): array
    {
        $codes = [];
        foreach ($equipment as $item) {
            $raw = (string)($item['code'] ?? '');
            $key = $this->normalizeEquipmentCode($raw);
            if ($key !== '') $codes[$key] = $raw;
        }
        return $codes;
    }

    private function normalizeEquipmentCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/u', '', $code);
        return str_replace(['–', '—', '‑'], '-', $code);
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value);
    }

    private function resolveEquipmentRefs(string $text, array $existing, array $equipmentCodes): array
    {
        $refs = [];
        foreach ($existing as $ref) {
            $key = $this->normalizeEquipmentCode((string)$ref);
            if ($key !== '' && isset($equipmentCodes[$key])) $refs[$key] = $equipmentCodes[$key];
        }
        if (trim($text) === '' || !$equipmentCodes) return array_values($refs);

        $normalizedText = strtoupper($text);
        $normalizedText = str_replace(['–', '—', '‑'], '-', $normalizedText);
        $normalizedText = preg_replace('/(?<=YD)\s+(?=\d)/u', '', $normalizedText);
        $normalizedText = preg_replace('/(?<=YD)\s*-\s*(?=\d)/u', '-', $normalizedText);

        preg_match_all('/\b(?:YD|HD|H|P)\s*-?\s*\d+[A-Z]?\b/iu', $normalizedText, $matches);
        foreach ($matches[0] ?? [] as $raw) {
            $key = $this->normalizeEquipmentCode($raw);
            if (isset($equipmentCodes[$key])) $refs[$key] = $equipmentCodes[$key];
        }

        preg_match_all('/\b(YD|HD|H|P)\s*-?\s*(\d+)\s*(?:-|\b(?:ILE|İLE|TO|ARASI)\b)\s*(?:\1\s*-?\s*)?(\d+)\b/iu', $normalizedText, $ranges, PREG_SET_ORDER);
        foreach ($ranges as $range) {
            $prefix = strtoupper($range[1]); $from = (int)$range[2]; $to = (int)$range[3];
            if ($from > $to) [$from, $to] = [$to, $from];
            for ($n = $from; $n <= $to; $n++) {
                foreach ([$prefix . $n, $prefix . '-' . $n] as $key) {
                    if (isset($equipmentCodes[$key])) $refs[$key] = $equipmentCodes[$key];
                }
            }
        }
        return array_values($refs);
    }
}
