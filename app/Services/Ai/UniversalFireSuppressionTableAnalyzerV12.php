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
        $result = $this->bindControlEquipmentFromFindings($result);
        $result = $this->recalculateSystemSummaries($result);

        foreach ($result['systems'] ?? [] as $systemIndex => $_system) {
            unset($result['systems'][$systemIndex]['findings']);
        }

        $result['analyzer']['version'] = '12.8.0';
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

            $refs = array_merge(
                (array)($finding['affected_equipment'] ?? []),
                (array)($finding['equipment_refs'] ?? []),
                (array)($finding['_equipment_refs'] ?? []),
                $projectionRefs[$id] ?? []
            );
            $refs = $this->resolveEquipmentRefsFromTextAndExisting($description, $refs, $systemEquipment);

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

        foreach ($result['systems'] ?? [] as $systemIndex => $_system) {
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

    private function groupControlsByCode(array $result): array
    {
        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $grouped = [];

            foreach ((array)($system['control_items'] ?? []) as $control) {
                if (!is_array($control)) continue;

                $code = $this->normalizeControlCode((string)($control['code'] ?? ''));
                $key = $code !== '' ? $code : '__NO_CODE__';

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'code' => $code !== '' ? $code : null,
                        'description' => $control['description'] ?? null,
                        'results' => [],
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

                if ($status !== null) {
                    if (!isset($grouped[$key]['results'][$status])) {
                        $grouped[$key]['results'][$status] = [];
                    }
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

    /**
     * Final deterministic relation pass.
     * A finding such as "5.41) YD1, YD2 ..." is the equipment relation for
     * control 5.41. The finding supplies the exact equipment set; the control
     * supplies the actual observed status. Nothing is invented.
     */
    private function bindControlEquipmentFromFindings(array $result): array
    {
        $findingsBySystemAndCode = [];

        foreach ((array)($result['findings'] ?? []) as $finding) {
            if (!is_array($finding)) continue;

            $code = $this->extractCriterionCode((string)($finding['description'] ?? ''));
            if ($code === '') continue;

            $systemKey = $this->normalizeKey((string)($finding['system_name'] ?? ''));
            $refs = array_values(array_unique(array_filter(array_map(
                'strval',
                (array)($finding['affected_equipment'] ?? [])
            ))));
            if (!$refs) continue;

            $key = $systemKey . '|' . $code;
            $findingsBySystemAndCode[$key] = array_values(array_unique(array_merge(
                $findingsBySystemAndCode[$key] ?? [],
                $refs
            )));
        }

        foreach ($result['systems'] ?? [] as $systemIndex => $system) {
            $systemKey = $this->normalizeKey((string)($system['name'] ?? ''));
            $systemCodes = [];
            foreach ((array)($system['components'] ?? []) as $component) {
                $code = trim((string)($component['code'] ?? ''));
                if ($code !== '') $systemCodes[$this->normalizeEquipmentCode($code)] = $code;
            }

            foreach ((array)($system['control_items'] ?? []) as $controlIndex => $control) {
                $code = $this->normalizeControlCode((string)($control['code'] ?? ''));
                if ($code === '') continue;

                $refs = [];
                foreach ([$systemKey . '|' . $code, '|' . $code] as $key) {
                    foreach ((array)($findingsBySystemAndCode[$key] ?? []) as $ref) {
                        $normalized = $this->normalizeEquipmentCode((string)$ref);
                        if (isset($systemCodes[$normalized])) {
                            $refs[$normalized] = $systemCodes[$normalized];
                        }
                    }
                }
                if (!$refs) continue;

                $results = (array)($control['results'] ?? []);
                $statusKeys = array_keys($results);

                // If the source control has an observed status, attach the
                // finding's exact equipment to that status. If there is no
                // status, do not manufacture one.
                foreach ($statusKeys as $status) {
                    $existing = (array)($results[$status] ?? []);
                    $results[$status] = array_values(array_unique(array_merge($existing, array_values($refs))));
                }

                $result['systems'][$systemIndex]['control_items'][$controlIndex]['results'] = $results;
            }
        }

        return $result;
    }

    private function extractCriterionCode(string $text): string
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*\)/u', $text, $m)) {
            return $this->normalizeControlCode($m[1]);
        }
        return '';
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

                    if (!isset($result['systems'][$systemIndex]['control_items'][$controlIndex]['results'][$status])) {
                        $result['systems'][$systemIndex]['control_items'][$controlIndex]['results'][$status] = [];
                    }

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
            $result['systems'][$systemIndex]['status'] = $udControlCount > 0
                ? 'uygun_degil'
                : (!empty($system['control_items']) ? 'uygun' : 'belirtilmemis');
        }

        return $result;
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
            if (preg_match('/(?<![A-Z0-9])' . preg_quote($key, '/') . '(?![A-Z0-9])/u', $normalizedText)) {
                $refs[$key] = $allCodes[$key];
            }
        }

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
        $status = preg_replace('/\s+/u', '', $status);
        $status = str_replace(['.', '_', '-'], '', $status);
        return $status !== '' ? $status : null;
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
