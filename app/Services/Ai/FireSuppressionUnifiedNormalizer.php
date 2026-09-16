<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Single orchestration point for fire-suppression report normalization.
 * Existing extraction services are intentionally left untouched.
 */
class FireSuppressionUnifiedNormalizer
{
    public function __construct(
        private readonly TemplateDrivenFireSuppressionCriterionExtractor $reportExtractor,
        private readonly CamelotCriterionNormalizer $criterionNormalizer,
        private readonly TemplateDiscoveryReportNormalizer $reportNormalizer,
    ) {
    }

    public function normalize(string $pdfPath, array $semantic): array
    {
        $this->validateGeminiTemplate($semantic);

        // 1) Existing pipeline receives the ORIGINAL Gemini template.
        // This protects equipment/result extraction from criterion post-processing.
        $extracted = $this->reportExtractor->extract($pdfPath, $semantic);

        // 2) Camelot resolves concrete criteria from coordinates independently.
        $criterionSemantic = $this->criterionNormalizer->normalize($semantic, $pdfPath);

        // 3) Apply only concrete criteria to the already extracted controls.
        $extracted = $this->applyConcreteCriteria($extracted, $criterionSemantic);

        // 4) Stable API contract.
        return $this->reportNormalizer->normalize($extracted);
    }

    private function validateGeminiTemplate(array $semantic): void
    {
        $systems = (array) ($semantic['template']['fire_systems']['systems'] ?? []);

        if ($systems === []) {
            throw new RuntimeException('Gemini Template yetersiz: fire_systems.systems bulunamadı.');
        }

        $usableSystems = 0;
        foreach ($systems as $system) {
            if (!is_array($system)) {
                continue;
            }

            $codes = [];
            $results = [];
            foreach ((array) ($system['control_items'] ?? []) as $control) {
                if (!is_array($control)) {
                    continue;
                }
                $codes = array_merge($codes, (array) ($control['control_code_patterns'] ?? []));
                $results = array_merge($results, (array) ($control['result_patterns'] ?? []));
            }

            $matrix = (array) ($system['control_matrix'] ?? []);
            $camelot = (array) ($matrix['camelot_extraction'] ?? []);
            $codes = array_merge($codes, (array) ($camelot['control_code_patterns'] ?? []));
            $results = array_merge($results, (array) ($camelot['result_cell_patterns'] ?? []));

            if ($this->hasPatterns($codes) && $this->hasPatterns($results)) {
                $usableSystems++;
            }
        }

        if ($usableSystems === 0) {
            throw new RuntimeException(
                'Gemini Template yetersiz: hiçbir yangın sistemi için kontrol kodu ve sonuç hücresi patterni birlikte keşfedilemedi.'
            );
        }
    }

    private function applyConcreteCriteria(array $extracted, array $criterionSemantic): array
    {
        $criteriaSystems = (array) ($criterionSemantic['template']['fire_systems']['systems'] ?? []);
        if ($criteriaSystems === []) {
            return $extracted;
        }

        $data =& $extracted;
        if (isset($extracted['extracted_data']) && is_array($extracted['extracted_data'])) {
            $data =& $extracted['extracted_data'];
        }

        $systems =& $data['systems'];
        if (!isset($systems) || !is_array($systems)) {
            return $extracted;
        }

        foreach ($systems as &$targetSystem) {
            if (!is_array($targetSystem)) {
                continue;
            }

            $targetName = $this->normalizeLabel($targetSystem['name'] ?? $targetSystem['system_name'] ?? null);
            if ($targetName === '') {
                continue;
            }

            foreach ($criteriaSystems as $criteriaSystem) {
                if (!is_array($criteriaSystem)) {
                    continue;
                }

                $criteriaName = $this->normalizeLabel($criteriaSystem['system_name'] ?? null);
                if ($criteriaName === '' || $criteriaName !== $targetName) {
                    continue;
                }

                $criteriaByCode = [];
                foreach ((array) ($criteriaSystem['control_items'] ?? []) as $criterion) {
                    if (!is_array($criterion) || empty($criterion['code'])) {
                        continue;
                    }
                    $criteriaByCode[$this->normalizeCode($criterion['code'])] = $criterion;
                }

                foreach ((array) ($targetSystem['control_items'] ?? []) as &$control) {
                    if (!is_array($control)) {
                        continue;
                    }
                    $code = $this->normalizeCode($control['code'] ?? null);
                    if ($code !== '' && isset($criteriaByCode[$code])) {
                        $criterion = $criteriaByCode[$code];
                        if (!empty($criterion['criterion'])) {
                            $control['criterion'] = $criterion['criterion'];
                        }
                        if (!empty($criterion['source_pages'])) {
                            $control['source_pages'] = $criterion['source_pages'];
                        }
                    }
                }
                unset($control);
            }
        }
        unset($targetSystem);

        return $extracted;
    }

    private function hasPatterns(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (trim((string) $pattern) !== '') {
                return true;
            }
        }
        return false;
    }

    private function normalizeCode(mixed $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim((string) $value)) ?? '', 'UTF-8');
    }

    private function normalizeLabel(mixed $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '', 'UTF-8');
    }
}
