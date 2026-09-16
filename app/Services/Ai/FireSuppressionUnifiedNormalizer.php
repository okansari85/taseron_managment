<?php

namespace App\Services\Ai;

/**
 * Single orchestration point for fire-suppression report normalization.
 *
 * This class intentionally does not replace or modify the existing extraction
 * services. It composes them in the correct order so the existing equipment /
 * result extraction receives the original Gemini template, while concrete
 * Camelot criteria are resolved afterwards.
 */
class FireSuppressionUnifiedNormalizer
{
    public function __construct(
        private readonly TemplateDrivenFireSuppressionCriterionExtractor $reportExtractor,
        private readonly CamelotCriterionNormalizer $criterionNormalizer,
        private readonly FireSuppressionResultMerger $resultMerger,
        private readonly TemplateDiscoveryReportNormalizer $reportNormalizer,
    ) {
    }

    /**
     * Normalize a discovered Gemini template into the final report structure.
     *
     * Order is deliberate:
     * 1. Existing extraction runs against the untouched Gemini template.
     * 2. Camelot resolves concrete criteria independently.
     * 3. Criteria are merged onto the already extracted result.
     * 4. Existing final normalization produces the API-ready structure.
     */
    public function normalize(string $pdfPath, array $semantic): array
    {
        $extracted = $this->reportExtractor->extract($pdfPath, $semantic);

        $criterionSemantic = $this->criterionNormalizer->normalize($semantic, $pdfPath);

        $merged = $this->resultMerger->merge($extracted, $criterionSemantic);

        return $this->reportNormalizer->normalize($merged);
    }
}
