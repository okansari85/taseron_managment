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
            $systems[] = [
                'name' => $name,
                'category' => trim((string) ($system['category'] ?? '')) ?: 'diger',
                'equipment_count' => count($components),
                'equipment_count_known' => count($components) > 0,
                'control_count' => 0,
                'components' => $components,
                'control_items' => $this->cleanControls((array) ($system['control_items'] ?? [])),
            ];
        }
        if ($coordinatePages) {
            $coordinatePages = array_values(array_filter($coordinatePages, fn($page) => is_array($page) && (isset($page['words']) || isset($page['tokens']) || isset($page['data']))));
            $coordinatePages = array_map(function (array $page): array {
                if (!isset($page['words']) && isset($page['data']) && is_array($page['data'])) $page['words'] = $page['data'];
                return $page;
            }, $coordinatePages);
            if ($coordinatePages) {
                $coordinateControls = $this->coordinateAnalyzer->analyze($coordinatePages, $this->equipmentFromSystems($systems));
                $systems = $this->applyCoordinateControls($systems, $coordinateControls);
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
            'covered_categories' => array_values(array_unique(array_filter(array_map(fn(array $s) => $s['category'] ?? null, $systems)))),
            'systems' => $systems,
            'findings' => $findings,
            'matched_inventory_items' => (array) ($base['matched_inventory_items'] ?? []),
            'candidate_inventory_items' => (array) ($base['candidate_inventory_items'] ?? []),
            'unmatched_codes' => (array) ($base['unmatched_codes'] ?? []),
            'analyzer' => [
                'version' => '12.13.2',
                'table_count' => (int) ($base['analyzer']['table_count'] ?? 0),
                'equipment_count' => array_sum(array_map(fn(array $s) => (int) $s['equipment_count'], $systems)),
                'control_count' => array_sum(array_map(fn(array $s) => (int) $s['control_count'], $systems)),
                'finding_count' => count($findings),
            ],
        ];
    }

    private function cleanComponents(array $components): array
    {
        $out = [];
        foreach ($components as $c) {
            if (!is_array($c)) continue;
            $code = trim((string) ($c['code'] ?? ''));
            if ($code === '') continue;
            $out[$this->normalizeEquipmentCode($code)] = [
                'code' => $code,
                'name' => $c['name'] ?? null,
                'location' => $c['location'] ?? $c['location_note'] ?? null,
                'brand' => $c['brand'] ?? null,
                'model' => $c['model'] ?? null,
                'serial_no' => $c['serial_no'] ?? $c['serial'] ?? null,
                'properties' => (array) ($c['properties'] ?? []),
                'source_pages' => array_values(array_unique(array_map('intval', (array) ($c['source_pages'] ?? [])))),
            ];
        }
        return array_values($out);
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

    private function applyCoordinateControls(array $systems, array $maps): array
    {
        foreach ($maps as $map) {
            if (!is_array($map)) continue;
            $code = $this->normalizeControlCode((string) ($map['code'] ?? ''));
            $status = $this->normalizeControlStatus($map['status'] ?? null);
            $refs = $this->normalizeEquipmentRefs((array) ($map['equipment_refs'] ?? []));
            if ($code === '' || $status === null || !$refs) continue;
            foreach ($systems as $si => $system) {
                $valid = [];
                foreach ((array) ($system['components'] ?? []) as $c) {
                    $raw = trim((string) ($c['code'] ?? ''));
                    if ($raw !== '') $valid[$this->normalizeEquipmentCode($raw)] = $raw;
                }
                $matched = [];
                foreach ($refs as $ref) {
                    $k = $this->normalizeEquipmentCode($ref);
                    if (isset($valid[$k])) $matched[$k] = $valid[$k];
                }
                if (!$matched) continue;
                $foundSameStatus = false;
                foreach ((array) ($systems[$si]['control_items'] ?? []) as $ci => $item) {
                    if ($this->normalizeControlCode((string) ($item['code'] ?? '')) !== $code) continue;
                    if ($this->normalizeControlStatus($item['status'] ?? null) !== $status) continue;
                    $foundSameStatus = true;
                    $systems[$si]['control_items'][$ci]['equipment_refs'] = array_values(array_unique(array_merge((array) ($item['equipment_refs'] ?? []), array_values($matched))));
                    $systems[$si]['control_items'][$ci]['source_pages'] = array_values(array_unique(array_merge((array) ($item['source_pages'] ?? []), array_map('intval', (array) ($map['source_pages'] ?? [])))));
                }
                if (!$foundSameStatus) {
                    $systems[$si]['control_items'][] = [
                        'code' => $code,
                        'description' => trim((string) ($map['description'] ?? '')) ?: null,
                        'status' => $status,
                        'equipment_refs' => array_values($matched),
                        'source_pages' => array_values(array_unique(array_map('intval', (array) ($map['source_pages'] ?? [])))),
                    ];
                }
            }
        }
        return $systems;
    }

    private function groupControlsByCode(array $systems): array
    {
        foreach ($systems as $si => $s) {
            $g = [];
            foreach ((array) ($s['control_items'] ?? []) as $c) {
                $code = $this->normalizeControlCode((string) ($c['code'] ?? ''));
                if ($code === '') continue;
                $g[$code] ??= ['code' => $code, 'description' => $c['description'] ?? null, 'results' => [], 'source_pages' => []];
                if (($g[$code]['description'] ?? null) === null && !empty($c['description'])) $g[$code]['description'] = $c['description'];
                $st = $this->normalizeControlStatus($c['status'] ?? null);
                if ($st !== null) {
                    $g[$code]['results'][$st] ??= [];
                    $g[$code]['results'][$st] = array_values(array_unique(array_merge($g[$code]['results'][$st], $this->normalizeEquipmentRefs((array) ($c['equipment_refs'] ?? [])))));
                }
                $g[$code]['source_pages'] = array_values(array_unique(array_merge($g[$code]['source_pages'], array_map('intval', (array) ($c['source_pages'] ?? [])))));
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
            if ($k !== '') $equipmentBySystem[$k] = array_values(array_filter(array_map(fn(array $c) => trim((string) ($c['code'] ?? '')), (array) ($s['components'] ?? []))));
        }
        $known = [];
        foreach ($equipmentBySystem as $equipment) foreach ($equipment as $code) $known[$this->normalizeEquipmentCode($code)] = $code;
        $out = [];
        foreach ($findings as $i => $f) {
            if (!is_array($f)) continue;
            $d = trim((string) ($f['description'] ?? ''));
            if ($d === '') continue;
            $sn = trim((string) ($f['system_name'] ?? ''));
            $refs = [];
            foreach (array_merge((array) ($f['affected_equipment'] ?? []), (array) ($f['equipment_refs'] ?? []), (array) ($f['_equipment_refs'] ?? [])) as $r) {
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
            $out[] = ['id' => trim((string) ($f['id'] ?? '')) ?: 'finding-' . ($i + 1), 'system_name' => $sn !== '' ? $sn : null, 'description' => $d, 'affected_equipment' => array_values($refs), 'source_pages' => array_values(array_unique(array_map('intval', (array) ($f['source_pages'] ?? []))))];
        }
        return $out;
    }

    private function isSystemWideFinding(string $d): bool
    {
        $t = mb_strtolower($d, 'UTF-8');
        foreach (['olmalıdır', 'bulunmalıdır', 'mevcut olmalıdır', 'bulundurulmalıdır', 'olması gerekmektedir'] as $p) if (str_contains($t, $p)) return true;
        return false;
    }

    private function looksLikeFindingRow(string $d): bool
    {
        $t = mb_strtolower(trim($d), 'UTF-8');
        return str_contains($t, 'bulgu') && !preg_match('/^\s*\d+(?:\.\d+)?\s*\)/u', $t);
    }

    private function equipmentFromSystems(array $systems): array
    {
        $e = [];
        foreach ($systems as $s) foreach ((array) ($s['components'] ?? []) as $c) {
            $code = trim((string) ($c['code'] ?? ''));
            if ($code !== '') $e[] = ['code' => $code];
        }
        return $e;
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
