<?php
namespace App\Services\Ai;

/**
 * V11 output-shape refinement for the universal fire-suppression analyzer.
 *
 * Equipment keeps its own status/data only. Relationships to controls/findings
 * are represented on the control/finding side through equipment_refs.
 */
class UniversalFireSuppressionTableAnalyzerV11 extends UniversalFireSuppressionTableAnalyzerV10
{
    public function analyze(array $pages, array $semantic): array
    {
        $result = parent::analyze($pages, $semantic);

        $equipment = array_values(array_map(function (array $item): array {
            unset($item['control_refs'], $item['finding_refs']);
            return $item;
        }, (array)($result['equipment'] ?? [])));

        $equipmentCodes = $this->normalizedEquipmentCodes($equipment);

        $findings = array_values(array_map(function (array $finding) use ($equipmentCodes): array {
            $refs = $this->resolveEquipmentRefs(
                (string)($finding['description'] ?? ''),
                (array)($finding['equipment_refs'] ?? []),
                $equipmentCodes
            );
            $finding['equipment_refs'] = $refs;
            return $finding;
        }, (array)($result['findings'] ?? [])));

        $systems = array_values(array_map(function (array $system) use ($equipmentCodes, $findings): array {
            $system['components'] = array_values(array_map(function (array $component): array {
                unset($component['control_refs'], $component['finding_refs']);
                return $component;
            }, (array)($system['components'] ?? [])));

            $system['control_items'] = array_values(array_map(function (array $control) use ($equipmentCodes): array {
                $control['equipment_refs'] = $this->resolveEquipmentRefs(
                    (string)($control['description'] ?? ''),
                    (array)($control['equipment_refs'] ?? []),
                    $equipmentCodes
                );
                return $control;
            }, (array)($system['control_items'] ?? [])));

            $system['findings'] = array_values(array_map(function (array $finding) use ($equipmentCodes): array {
                $finding['equipment_refs'] = $this->resolveEquipmentRefs(
                    (string)($finding['description'] ?? ''),
                    (array)($finding['equipment_refs'] ?? []),
                    $equipmentCodes
                );
                return $finding;
            }, (array)($system['findings'] ?? [])));

            return $system;
        }, (array)($result['systems'] ?? [])));

        $result['equipment'] = $equipment;
        $result['findings'] = $findings;
        $result['systems'] = $systems;
        $result['analyzer']['version'] = '11.0.0';

        return $result;
    }

    private function normalizedEquipmentCodes(array $equipment): array
    {
        $codes = [];
        foreach ($equipment as $item) {
            $raw = (string)($item['code'] ?? '');
            $key = $this->normalizeEquipmentCode($raw);
            if ($key !== '') {
                $codes[$key] = $raw;
            }
        }
        return $codes;
    }

    private function normalizeEquipmentCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/u', '', $code);
        $code = str_replace(['–', '—', '‑'], '-', $code);
        return $code;
    }

    private function resolveEquipmentRefs(string $text, array $existing, array $equipmentCodes): array
    {
        $refs = [];

        foreach ($existing as $ref) {
            $key = $this->normalizeEquipmentCode((string)$ref);
            if ($key !== '' && isset($equipmentCodes[$key])) {
                $refs[$key] = $equipmentCodes[$key];
            }
        }

        if (trim($text) === '' || !$equipmentCodes) {
            return array_values($refs);
        }

        // Normalize common variants: YD 14, YD-14, YD14.
        $normalizedText = strtoupper($text);
        $normalizedText = str_replace(['–', '—', '‑'], '-', $normalizedText);
        $normalizedText = preg_replace('/(?<=YD)\s+(?=\d)/u', '', $normalizedText);
        $normalizedText = preg_replace('/(?<=YD)\s*-\s*(?=\d)/u', '-', $normalizedText);

        // Explicit individual equipment codes.
        preg_match_all('/\b(?:YD|HD|H|P)\s*-?\s*\d+[A-Z]?\b/iu', $normalizedText, $matches);
        foreach ($matches[0] ?? [] as $raw) {
            $key = $this->normalizeEquipmentCode($raw);
            if (isset($equipmentCodes[$key])) {
                $refs[$key] = $equipmentCodes[$key];
            }
        }

        // Numeric ranges such as YD-33-YD-60 and YD-16 ile YD-72.
        preg_match_all('/\b(YD|HD|H|P)\s*-?\s*(\d+)\s*(?:-|\b(?:ILE|İLE|TO|ARASI)\b)\s*(?:\1\s*-?\s*)?(\d+)\b/iu', $normalizedText, $ranges, PREG_SET_ORDER);
        foreach ($ranges as $range) {
            $prefix = strtoupper($range[1]);
            $from = (int)$range[2];
            $to = (int)$range[3];
            if ($from > $to) [$from, $to] = [$to, $from];

            for ($n = $from; $n <= $to; $n++) {
                $key = $prefix . '-' . $n;
                if (isset($equipmentCodes[$key])) {
                    $refs[$key] = $equipmentCodes[$key];
                }
            }
        }

        return array_values($refs);
    }
}
