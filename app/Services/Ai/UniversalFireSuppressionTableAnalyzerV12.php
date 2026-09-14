<?php

namespace App\Services\Ai;

/** Final deterministic normalizer for fire-suppression reports. */
class UniversalFireSuppressionTableAnalyzerV12 extends UniversalFireSuppressionTableAnalyzerV10
{
    private CoordinateTableAnalyzer $coordinateAnalyzer;

    public function __construct(CoordinateTableAnalyzer $coordinateAnalyzer)
    {
        $this->coordinateAnalyzer = $coordinateAnalyzer;
    }

    public function analyze(array $pages, array $semantic = [], array $coordinatePages = []): array
    {
        $base = parent::analyze($pages, $semantic);
        $systems = [];

        foreach ((array) ($base['systems'] ?? []) as $system) {
            if (!is_array($system)) continue;
            $name = trim((string) ($system['name'] ?? ''));
            if ($name === '') continue;

            $components = $this->cleanComponents((array) ($system['components'] ?? []));
            $semanticControls = $this->semanticControlsForSystem(
                $semantic,
                $name,
                (string) ($system['category'] ?? '')
            );
            $baseControls = (array) ($system['control_items'] ?? []);

            $systems[] = [
                'name' => $name,
                'category' => trim((string) ($system['category'] ?? '')) ?: 'diger',
                'equipment_count' => count($components),
                'equipment_count_known' => count($components) > 0,
                'control_count' => 0,
                'components' => $components,
                'control_items' => $this->cleanControls($semanticControls ?: $baseControls),
            ];
        }

        /*
         * Coordinate mapping is deliberately PER SYSTEM.
         *
         * Gemini tells us which control belongs to which system. From that
         * point on, the coordinate analyzer gets only that system's equipment
         * and that system's control codes. It then finds the equipment axis,
         * control axis and the status cells at their intersections.
         *
         * This prevents e.g. YD controls from being mixed with pump controls
         * just because both use 5.xx control numbers in the same PDF.
         * Whole-unit systems (no equipment) are not sent to the matrix mapper;
         * their Gemini control_items remain authoritative.
         */
        if ($coordinatePages) {
            $coordinatePages = array_values(array_filter(
                $coordinatePages,
                fn($page) => is_array($page) && (isset($page['words']) || isset($page['tokens']) || isset($page['data']))
            ));
            $coordinatePages = array_map(function (array $page): array {
                if (!isset($page['words']) && isset($page['data']) && is_array($page['data'])) {
                    $page['words'] = $page['data'];
                }
                return $page;
            }, $coordinatePages);

            foreach ($systems as $si => $system) {
                $equipment = [];
                foreach ((array) ($system['components'] ?? []) as $component) {
                    $code = trim((string) ($component['code'] ?? ''));
                    if ($code !== '') $equipment[] = ['code' => $code];
                }

                if (!$equipment) continue;

                $controlCodes = [];
                foreach ((array) ($system['control_items'] ?? []) as $item) {
                    $code = trim((string) ($item['code'] ?? ''));
                    if ($code !== '') $controlCodes[$this->normalizeControlCode($code)] = $code;
                }
                $controlCodes = array_values($controlCodes);
                if (!$controlCodes) continue;

                $maps = $this->coordinateAnalyzer->analyze(
                    $coordinatePages,
                    $equipment,
                    $controlCodes
                );

                if ($maps) {
                    $systems[$si] = $this->applyCoordinateControlsToSystem(
                        $systems[$si],
                        $maps
                    );
                }
            }
        }

        $systems = $this->groupControlsByCode($systems);
        $findings = $this->normalizeFindings((array) ($base['findings'] ?? []), $systems);
        $report = (array) ($semantic['report'] ?? []);

        return [
            'report' => [
                'report_no' => $report['report_no'] ?? null,
                'company_name' => $report['company_name'] ?? null,
                'control_date' => $report['control_date'] ?? null,
                'next_control_date' => $report['next_control_date'] ?? null,
                'overall_result' => $report['overall_result'] ?? null,
            ],
            'covered_categories' => array_values(array_unique(array_filter(array_map(
                fn(array $s) => $s['category'] ?? null,
                $systems
            )))),
            'systems' => $systems,
            'findings' => $findings,
            'matched_inventory_items' => (array) ($base['matched_inventory_items'] ?? []),
            'candidate_inventory_items' => (array) ($base['candidate_inventory_items'] ?? []),
            'unmatched_codes' => (array) ($base['unmatched_codes'] ?? []),
            'analyzer' => [
                'version' => '12.16.0',
                'table_count' => (int) ($base['analyzer']['table_count'] ?? 0),
                'equipment_count' => array_sum(array_map(fn(array $s) => (int) $s['equipment_count'], $systems)),
                'control_count' => array_sum(array_map(fn(array $s) => (int) $s['control_count'], $systems)),
                'finding_count' => count($findings),
            ],
        ];
    }

    private function semanticControlsForSystem(array $semantic, string $systemName, string $category): array
    {
        $target = $this->normalizeKey($systemName);
        foreach ((array) ($semantic['systems'] ?? []) as $semanticSystem) {
            if (!is_array($semanticSystem)) continue;
            $name = trim((string) ($semanticSystem['name'] ?? ''));
            $semanticCategory = trim((string) ($semanticSystem['category'] ?? ''));
            if ($name === '') continue;
            if ($this->normalizeKey($name) !== $target && ($semanticCategory === '' || $semanticCategory !== $category)) continue;

            $controls = [];
            foreach ((array) ($semanticSystem['control_items'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $code = $this->normalizeControlCode((string) ($item['code'] ?? ''));
                if ($code === '') continue;
                $description = trim((string) ($item['description'] ?? ''));
                $status = $this->normalizeControlStatus($item['status'] ?? null);
                $controls[] = [
                    'code' => $code,
                    'description' => $description !== '' ? $description : null,
                    'status' => $status,
                    'equipment_refs' => [],
                    'source_pages' => [],
                ];
            }
            return $controls;
        }
        return [];
    }

    private function cleanComponents(array $components): array
    {
        $out = [];
        foreach ($components as $c) {
            if (!is_array($c)) continue;
            $code = trim((string) ($c['code'] ?? ''));
            if ($code === '') continue;
            $out[] = [
                'code' => $code,
                'name' => trim((string) ($c['name'] ?? '')) ?: null,
                'location' => trim((string) ($c['location'] ?? '')) ?: null,
                'brand' => trim((string) ($c['brand'] ?? '')) ?: null,
                'model' => trim((string) ($c['model'] ?? '')) ?: null,
                'serial_no' => trim((string) ($c['serial_no'] ?? '')) ?: null,
                'properties' => is_array($c['properties'] ?? null) ? $c['properties'] : [],
                'source_pages' => array_values(array_unique(array_map('intval', (array) ($c['source_pages'] ?? [])))),
            ];
        }
        return $out;
    }

    private function cleanControls(array $controls): array
    {
        $out = [];
        foreach ($controls as $c) {
            if (!is_array($c)) continue;
            $d = trim((string) ($c['description'] ?? ''));
            if ($this->looksLikeFindingRow($d)) continue;
            $code = $this->normalizeControlCode((string) ($c['code'] ?? ''));
            if ($code === '') continue;
            $out[] = [
                'code' => $code,
                'description' => $d !== '' ? $d : null,
                'status' => $this->normalizeControlStatus($c['status'] ?? null),
                'equipment_refs' => $this->normalizeEquipmentRefs((array) ($c['equipment_refs'] ?? [])),
                'source_pages' => array_values(array_unique(array_map('intval', (array) ($c['source_pages'] ?? [])))),
            ];
        }
        return $out;
    }

    private function applyCoordinateControlsToSystem(array $system, array $maps): array
    {
        $valid = [];
        foreach ((array) ($system['components'] ?? []) as $component) {
            $raw = trim((string) ($component['code'] ?? ''));
            if ($raw !== '') $valid[$this->normalizeEquipmentCode($raw)] = $raw;
        }
        if (!$valid) return $system;

        foreach ($maps as $map) {
            if (!is_array($map)) continue;
            $code = $this->normalizeControlCode((string) ($map['code'] ?? ''));
            $status = $this->normalizeControlStatus($map['status'] ?? null);
            if ($code === '' || $status === null) continue;

            $matched = [];
            foreach ((array) ($map['equipment_refs'] ?? []) as $ref) {
                $k = $this->normalizeEquipmentCode((string) $ref);
                if ($k !== '' && isset($valid[$k])) $matched[$k] = $valid[$k];
            }
            if (!$matched) continue;

            $foundSameStatus = false;
            foreach ((array) ($system['control_items'] ?? []) as $ci => $item) {
                if ($this->normalizeControlCode((string) ($item['code'] ?? '')) !== $code) continue;
                if ($this->normalizeControlStatus($item['status'] ?? null) !== $status) continue;

                $foundSameStatus = true;
                $system['control_items'][$ci]['equipment_refs'] = array_values(array_unique(array_merge(
                    (array) ($item['equipment_refs'] ?? []),
                    array_values($matched)
                )));
                $system['control_items'][$ci]['source_pages'] = array_values(array_unique(array_merge(
                    (array) ($item['source_pages'] ?? []),
                    array_map('intval', (array) ($map['source_pages'] ?? []))
                )));
            }

            if (!$foundSameStatus) {
                $system['control_items'][] = [
                    'code' => $code,
                    'description' => trim((string) ($map['description'] ?? '')) ?: null,
                    'status' => $status,
                    'equipment_refs' => array_values($matched),
                    'source_pages' => array_values(array_unique(array_map('intval', (array) ($map['source_pages'] ?? [])))),
                ];
            }
        }

        return $system;
    }

    private function groupControlsByCode(array $systems): array
    {
        foreach ($systems as $si => $s) {
            $g = [];
            foreach ((array) ($s['control_items'] ?? []) as $c) {
                $code = $this->normalizeControlCode((string) ($c['code'] ?? ''));
                if ($code === '') continue;
                $g[$code] ??= [
                    'code' => $code,
                    'description' => $c['description'] ?? null,
                    'results' => [],
                    'source_pages' => [],
                ];
                if (($g[$code]['description'] ?? null) === null && !empty($c['description'])) {
                    $g[$code]['description'] = $c['description'];
                }
                $st = $this->normalizeControlStatus($c['status'] ?? null);
                if ($st !== null) {
                    $g[$code]['results'][$st] ??= [];
                    $g[$code]['results'][$st] = array_values(array_unique(array_merge(
                        $g[$code]['results'][$st],
                        $this->normalizeEquipmentRefs((array) ($c['equipment_refs'] ?? []))
                    )));
                }
                $g[$code]['source_pages'] = array_values(array_unique(array_merge(
                    $g[$code]['source_pages'],
                    array_map('intval', (array) ($c['source_pages'] ?? []))
                )));
            }
            $systems[$si]['control_items'] = array_values($g);
            $systems[$si]['control_count'] = count($g);
        }
        return $systems;
    }

    private function normalizeFindings(array $findings, array $systems): array
    {
        $equipmentBySystem = [];
        foreach ($systems as $s) {
            $k = $this->normalizeKey((string) ($s['name'] ?? ''));
            if ($k !== '') {
                $equipmentBySystem[$k] = array_values(array_filter(array_map(
                    fn(array $c) => trim((string) ($c['code'] ?? '')),
                    (array) ($s['components'] ?? [])
                )));
            }
        }

        $known = [];
        foreach ($equipmentBySystem as $equipment) {
            foreach ($equipment as $code) $known[$this->normalizeEquipmentCode($code)] = $code;
        }

        $out = [];
        foreach ($findings as $i => $f) {
            if (!is_array($f)) continue;
            $d = trim((string) ($f['description'] ?? ''));
            if ($d === '') continue;
            $sn = trim((string) ($f['system_name'] ?? ''));
            $refs = [];

            foreach (array_merge(
                (array) ($f['affected_equipment'] ?? []),
                (array) ($f['equipment_refs'] ?? []),
                (array) ($f['_equipment_refs'] ?? [])
            ) as $r) {
                $k = $this->normalizeEquipmentCode((string) $r);
                if ($k !== '' && isset($known[$k])) $refs[$k] = $known[$k];
            }

            $text = strtoupper(str_replace(['–', '—', '‑', '−'], '-', $d));
            foreach ($known as $k => $c) {
                $pattern = '/(?<![A-Z0-9])' . preg_quote($k, '/') . '(?![A-Z0-9])/u';
                if (preg_match($pattern, $text)) $refs[$k] = $c;
            }

            if (!$refs && $sn !== '' && $this->isSystemWideFinding($d)) {
                $systemKey = $this->normalizeKey($sn);
                foreach ($equipmentBySystem as $knownSystem => $equipment) {
                    if ($knownSystem === $systemKey || str_contains($knownSystem, $systemKey) || str_contains($systemKey, $knownSystem)) {
                        foreach ($equipment as $c) $refs[$this->normalizeEquipmentCode($c)] = $c;
                        break;
                    }
                }
            }

            $out[] = [
                'id' => trim((string) ($f['id'] ?? '')) ?: 'finding-' . ($i + 1),
                'system_name' => $sn !== '' ? $sn : null,
                'description' => $d,
                'affected_equipment' => array_values($refs),
                'source_pages' => array_values(array_unique(array_map('intval', (array) ($f['source_pages'] ?? [])))),
            ];
        }
        return $out;
    }

    private function isSystemWideFinding(string $d): bool
    {
        $t = mb_strtolower($d, 'UTF-8');
        foreach (['olmalıdır', 'bulunmalıdır', 'mevcut olmalıdır', 'bulundurulmalıdır', 'olması gerekmektedir'] as $p) {
            if (str_contains($t, $p)) return true;
        }
        return false;
    }

    private function looksLikeFindingRow(string $d): bool
    {
        $t = mb_strtolower(trim($d), 'UTF-8');
        return str_contains($t, 'bulgu') && !preg_match('/^\s*\d+(?:\.\d+)?\s*\)/u', $t);
    }

    private function normalizeEquipmentRefs(array $refs): array
    {
        $o = [];
        foreach ($refs as $r) {
            $r = trim((string) $r);
            if ($r === '') continue;
            $k = $this->normalizeEquipmentCode($r);
            if ($k !== '') $o[$k] = $r;
        }
        return array_values($o);
    }

    private function normalizeControlStatus(mixed $s): ?string
    {
        $v = strtoupper(trim((string) $s));
        $v = str_replace(['.', ' ', '_', '-'], '', $v);
        return $v !== '' ? $v : null;
    }

    private function normalizeControlCode(string $c): string
    {
        return preg_replace('/\s+/u', '', trim($c));
    }

    private function normalizeEquipmentCode(string $c): string
    {
        $c = strtoupper(trim($c));
        $c = preg_replace('/\s+/u', '', $c);
        return str_replace(['–', '—', '‑', '−', '_'], '-', $c);
    }

    private function normalizeKey(string $v): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($v), 'UTF-8'));
    }
}
