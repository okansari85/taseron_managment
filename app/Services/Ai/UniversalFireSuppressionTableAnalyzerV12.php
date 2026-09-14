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
        $result = $this->normalizeFindings($result);

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

        $result = $this->groupControlsByCode($result);
        $result = $this->recalculateSystemSummaries($result);

        // V12 owns the canonical finding relationship at root level only.
        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            unset($result['systems'][$systemIndex]['findings']);
        }

        $result['analyzer']['version'] = '12.6.0';
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
     * Findings are canonical root-level records.
     * Their equipment relation is affected_equipment only.
     * There is deliberately no finding -> control-code binding.
     */
    private function normalizeFindings(array $result): array
    {
        $systemEquipment = [];
        foreach ($result['systems'] ?? [] as $system) {
            if (!is_array($system)) continue;
            $name = $this->normalizeKey((string)($system['name'] ?? ''));
            if ($name === '') continue;
            $systemEquipment[$name] = array_values(array_filter(array_map(
                fn ($component) => trim((string)($component['code'] ?? '')),
                (array)($system['components'] ?? [])
            )));
        }

        // V11 may already have produced the canonical equipment refs internally,
        // while semantic projections may still carry the same relation under systems.
        $projectionRefs = [];
        foreach ($result['systems'] ?? [] as $system) {
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

            $id = trim((string)($finding['id'] ?? '')) ?: 'finding-' . ($index + 1);
            $description = trim((string)($finding['description'] ?? ''));
            $systemNameRaw = trim((string)($finding['system_name'] ?? ''));
            $systemName = $this->normalizeKey($systemNameRaw);

            $refs = (array)($finding['affected_equipment'] ?? []);
            $refs = array_merge($refs, (array)($finding['equipment_refs'] ?? []));
            $refs = array_merge($refs, (array)($finding['_equipment_refs'] ?? []));
            $refs = array_merge($refs, $projectionRefs[$id] ?? []);

            $refs = $this->resolveEquipmentRefsFromTextAndExisting($description, $refs, $systemEquipment);

            // A system-wide requirement such as "Yangın dolaplarının kullanma
            // talimatları olmalıdır" legitimately applies to all equipment of that
            // system. This is semantic finding scope, not control-code matching.
            if (!$refs && $systemName !== '' && $this->isSystemWideFinding($description)) {
                foreach ($systemEquipment as $knownSystem => $equipment) {
                    if ($knownSystem === $systemName || str_contains($knownSystem, $systemName) || str_contains($systemName, $knownSystem)) {
                        $refs = $equipment;
                        break;
                    }
                }
            }

            $result['findings'][$index] = [
                'id' => $id,
                'system_name' => $systemNameRaw !== '' ? $systemNameRaw : null,
                'description' => $description,
                'affected_equipment' => array_values(array_unique($refs)),
                'source_pages' => array_values($finding['source_pages'] ?? []),
            ];
        }

        // The relation must exist only once: findings[].affected_equipment.
        foreach ($result['systems'] ?? $systemIndex => $system) {
            unset($result['systems'][$systemIndex]['findings']);
        }

        return $result;
    }

    private function isSystemWideFinding(string $description): bool
    {
        $text = mb_strtolower(trim($description), 'UTF-8');
        if ($text === '') return false;

        foreach ([
            'olmalıdır',
            'bulunmalıdır',
            'mevcut olmalıdır',
            'bulundurulmalıdır',
            'olması gerekmektedir',
        ] as $pattern) {
            if (str_contains($text, $pattern)) return true;
        }

        return false;
    }

    /**
     * One control record per code.
     * Results always expose the four canonical statuses.
     * Equipment is added only when the source table/coordinate analyzer
     * actually identifies equipment for that status.
     */
    private function groupControlsByCode(array $result): array
    {
        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $grouped = [];

            foreach ((array)($system['control_items'] ?? []) as $control) {
                if (!is_array($control)) continue;

                $code = $this->normalizeControlCode((string)($control['code'] ?? ''));
                if ($code === '') {
                    // Uncoded system checks cannot safely be merged into a coded
                    // control. Keep them as a single deterministic record.
                    $code = null;
                }

                $key = $code ?? '__NO_CODE__';
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'code' => $code,
                        'description' => $control['description'] ?? null,
                        'results' => [
                            'U' => [],
                            'UD' => [],
                            'N' => [],
                            'GD' => [],
                        ],
                        'source_pages' => [],
                    ];
                }

                $description = trim((string)($control['description'] ?? ''));
                if (($grouped[$key]['description'] ?? null) === null && $description !== '') {
                    $grouped[$key]['description'] = $description;
                }

                $status = $this->normalizeControlStatus($control['status'] ?? null);
                $refs = array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array)($control['equipment_refs'] ?? [])
                ))));

                if ($status !== null && isset($grouped[$key]['results'][$status])) {
                    $grouped[$key]['results'][$status] = array_values(array_unique(array_merge(
                        $grouped[$key]['results'][$status],
                        $refs
                    )));
                }

                $grouped[$key]['source_pages'] = array_values(array_unique(array_merge(
                    $grouped[$key]['source_pages'],
                    array_values($control['source_pages'] ?? [])
                )));
            }

            $result['systems'][$systemIndex]['control_items'] = array_values($grouped);
        }

        return $result;
    }

    private function applyCoordinateControls(array $result, array $coordinateControls): array
    {
        foreach ($coordinateControls as $control) {
            $code = $this->normalizeControlCode((string)($control['code'] ?? ''));
            $status = $this->normalizeControlStatus($control['status'] ?? null);
            $equipmentRefs = array_values(array_unique(array_filter(array_map(
                'strval',
                (array)($control['equipment_refs'] ?? [])
            ))));

            if ($code === '' || $status === null || !$equipmentRefs) continue;

            foreach ($result['systems'] ?? [] as $systemIndex => $system) {
                $systemCodes = array_map(
                    fn ($component) => trim((string)($component['code'] ?? '')),
                    $system['components'] ?? []
                );
                $matched = array_values(array_intersect($equipmentRefs, $systemCodes));
                if (!$matched) continue;

                foreach ($system['control_items'] ?? [] as $controlIndex => $item) {
                    if ($this->normalizeControlCode((string)($item['code'] ?? '')) !== $code) continue;

                    // Coordinate data is the authoritative equipment/status matrix
                    // source. It is merged into the canonical result bucket.
                    $existing = (array)($result['systems'][$systemIndex]['control_items'][$controlIndex]['results'][$status] ?? []);
                    $result['systems'][$systemIndex]['control_items'][$controlIndex]['results'][$status] = array_values(array_unique(array_merge($existing, $matched)));
                }
            }
        }

        return $result;
    }

    private function recalculateSystemSummaries(array $result): array
    {
        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $nonconforming = [];
            $udControlCount = 0;

            foreach ((array)($system['control_items'] ?? []) as $control) {
                $results = (array)($control['results'] ?? []);
                foreach ((array)($results['UD'] ?? []) as $ref) {
                    $key = $this->normalizeEquipmentCode((string)$ref);
                    if ($key !== '') $nonconforming[$key] = $ref;
                }
                if (!empty($results['UD'])) $udControlCount++;
            }

            foreach ((array)($result['findings'] ?? []) as $finding) {
                $findingSystem = $this->normalizeKey((string)($finding['system_name'] ?? ''));
                $systemName = $this->normalizeKey((string)($system['name'] ?? ''));
                if ($findingSystem === '' || $systemName === '') continue;
                if ($findingSystem !== $systemName && !str_contains($findingSystem, $systemName) && !str_contains($systemName, $findingSystem)) continue;

                foreach ((array)($finding['affected_equipment'] ?? []) as $ref) {
                    $key = $this->normalizeEquipmentCode((string)$ref);
                    if ($key !== '') $nonconforming[$key] = $ref;
                }
            }

            $equipmentCount = count((array)($system['components'] ?? []));
            $result['systems'][$systemIndex]['equipment_count'] = $equipmentCount;
            $result['systems'][$systemIndex]['equipment_count_known'] = $equipmentCount > 0;
            $result['systems'][$systemIndex]['nonconforming_equipment_count'] = count($nonconforming);
            $result['systems'][$systemIndex]['control_count'] = count((array)($system['control_items'] ?? []));
            $result['systems'][$systemIndex]['nonconforming_count'] = $udControlCount;
            $result['systems'][$systemIndex]['status'] = $this->deriveV12SystemStatus($system, $udControlCount);
        }

        return $result;
    }

    private function deriveV12SystemStatus(array $system, int $udControlCount): string
    {
        if ($udControlCount > 0) return 'uygun_degil';

        foreach ((array)($GLOBALS['__v12_unused'] ?? []) as $_) {
            // no-op; keeps this method independent from finding/control projection data
        }

        return !empty($system['control_items']) ? 'uygun' : 'belirtilmemis';
    }

    private function equipmentFromSystems(array $systems): array
    {
        $equipment = [];
        foreach ($systems as $system) {
            foreach ($system['components'] ?? [] as $component) {
                $code = trim((string)($component['code'] ?? ''));
                if ($code !== '') $equipment[] = ['code' => $code];
            }
        }
        return $equipment;
    }

    private function resolveEquipmentRefsFromTextAndExisting(string $text, array $existing, array $systemEquipment): array
    {
        $allCodes = [];
        foreach ($systemEquipment as $equipment) {
            foreach ($equipment as $code) {
                $key = $this->normalizeEquipmentCode($code);
                if ($key !== '') $allCodes[$key] = $code;
            }
        }

        $refs = [];
        foreach ($existing as $ref) {
            $key = $this->normalizeEquipmentCode((string)$ref);
            if ($key !== '' && isset($allCodes[$key])) $refs[$key] = $allCodes[$key];
        }

        $normalizedText = strtoupper(str_replace(['–', '—', '‑', '−'], '-', $text));

        foreach (array_keys($allCodes) as $key) {
            $pattern = preg_quote($key, '/');
            if (preg_match('/(?<![A-Z0-9])' . $pattern . '(?![A-Z0-9])/u', $normalizedText)) {
                $refs[$key] = $allCodes[$key];
            }
        }

        // Resolve explicit numeric ranges, but only to equipment that really exists
        // in the extracted components.
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
                    if (isset($allCodes[$key])) $refs[$key] = $allCodes[$key];
                }
            }
        }

        return array_values($refs);
    }

    private function normalizeControlStatus(mixed $status): ?string
    {
        $status = strtoupper(trim((string)$status));
        $status = str_replace(['.', ' ', '_', '-'], '', $status);
        if ($status === 'UD') return 'UD';
        if ($status === 'U') return 'U';
        if ($status === 'N') return 'N';
        if ($status === 'GD') return 'GD';
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

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value);
    }
}
