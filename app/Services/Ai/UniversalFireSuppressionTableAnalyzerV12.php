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

        // The semantic layer owns findings. Remove malformed narrative rows
        // that the generic table parser can otherwise misclassify as controls.
        $result = $this->removeFindingRowsFromControls($result);

        // If a finding contains real equipment codes and refers to the same
        // numbered criterion as a control item, bind those equipment refs to
        // that control as well. This is deterministic and works for every
        // report/system; it does not depend on a hard-coded system name.
        $result = $this->bindFindingEquipmentToControls($result);

        if ($coordinatePages !== []) {
            $coordinateControls = $this->coordinateAnalyzer->analyze(
                $coordinatePages,
                $this->equipmentFromSystems($result['systems'] ?? [])
            );
            $result = $this->applyCoordinateControls($result, $coordinateControls);
        }

        $result['analyzer']['version'] = '12.4.0';
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
                $description = trim((string)($control['description'] ?? ''));

                if (
                    $code !== ''
                    && isset($normalCodes[$code])
                    && $this->containsEquipmentCode($description, $knownCodes)
                ) {
                    continue;
                }

                if ($this->looksLikeDetachedFindingRow($description)) {
                    continue;
                }

                $key = $code . '|' . strtoupper(trim((string)($control['status'] ?? ''))) . '|' . $description;
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

    /**
     * Finding text is the strongest deterministic source for equipment refs.
     * When a finding contains equipment codes and starts with a criterion code
     * such as "5.41)", attach those refs to the matching control item in the
     * same system. This prevents equipment references from being lost between
     * the finding and its originating control criterion.
     */
    private function bindFindingEquipmentToControls(array $result): array
    {
        foreach ((array)($result['systems'] ?? []) as $systemIndex => $system) {
            $components = (array)($system['components'] ?? []);
            $equipmentCodes = [];
            foreach ($components as $component) {
                $raw = trim((string)($component['code'] ?? ''));
                $key = $this->normalizeEquipmentCode($raw);
                if ($key !== '') $equipmentCodes[$key] = $raw;
            }
            if (!$equipmentCodes) continue;

            $findingRefsByControl = [];
            foreach ((array)($system['findings'] ?? []) as $findingProjection) {
                $findingId = (string)($findingProjection['id'] ?? '');
                if ($findingId === '') continue;
                $refs = [];
                foreach ((array)($result['findings'] ?? []) as $finding) {
                    if ((string)($finding['id'] ?? '') !== $findingId) continue;
                    $refs = $this->resolveEquipmentRefs(
                        (string)($finding['description'] ?? ''),
                        (array)($finding['_equipment_refs'] ?? []),
                        $equipmentCodes
                    );
                    break;
                }
                if (!$refs) continue;

                $criterionCode = $this->extractCriterionCode($this->findingDescription($result, $findingId));
                if ($criterionCode !== '') {
                    $findingRefsByControl[$criterionCode] = array_values(array_unique(array_merge(
                        $findingRefsByControl[$criterionCode] ?? [],
                        $refs
                    )));
                }
            }

            if (!$findingRefsByControl) continue;

            foreach ((array)($system['control_items'] ?? []) as $controlIndex => $control) {
                $controlCode = trim((string)($control['code'] ?? ''));
                if ($controlCode === '' || !isset($findingRefsByControl[$controlCode])) continue;

                $existing = (array)($control['equipment_refs'] ?? []);
                $refs = array_values(array_unique(array_merge($existing, $findingRefsByControl[$controlCode])));
                $system['control_items'][$controlIndex]['scope'] = 'equipment';
                $system['control_items'][$controlIndex]['equipment_refs'] = $refs;
            }

            $result['systems'][$systemIndex] = $system;
        }

        return $result;
    }

    private function findingDescription(array $result, string $findingId): string
    {
        foreach ((array)($result['findings'] ?? []) as $finding) {
            if ((string)($finding['id'] ?? '') === $findingId) {
                return (string)($finding['description'] ?? '');
            }
        }
        return '';
    }

    private function extractCriterionCode(string $description): string
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*[\)]/u', $description, $match)) {
            return trim($match[1]);
        }
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*[\)]/u', $description, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    private function looksLikeDetachedFindingRow(string $description): bool
    {
        if ($description === '') return false;
        $normalized = preg_replace('/\s+/u', ' ', trim($description));
        return (bool)preg_match('/^\)+\s+/u', $normalized);
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

                    $equipmentRefs = $this->refsBelongingToSystem(
                        $refs,
                        (array)($system['components'] ?? [])
                    );
                    if (!$equipmentRefs) continue;

                    $system['control_items'][$controlIndex]['scope'] = 'equipment';
                    $system['control_items'][$controlIndex]['equipment_refs'] = array_values(array_unique(array_merge(
                        (array)($system['control_items'][$controlIndex]['equipment_refs'] ?? []),
                        $equipmentRefs
                    )));
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
        $wanted = array_fill_keys(
            array_filter(array_map(fn($r) => $this->normalizeEquipmentCode((string)$r), $refs)),
            true
        );
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
        return str_replace(['–', '—', '‑'], '-', $code);
    }
}
