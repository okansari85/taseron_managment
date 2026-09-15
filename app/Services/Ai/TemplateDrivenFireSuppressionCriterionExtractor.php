<?php

namespace App\Services\Ai;

/**
 * Backward-compatible orchestration entry point.
 *
 * The actual responsibilities are deliberately separated:
 * - TemplateDrivenFireSuppressionMatrixExtractor: Camelot equipment/matrix/results.
 * - FireSuppressionResultMerger: Gemini code/criterion + Camelot results.
 */
class TemplateDrivenFireSuppressionCriterionExtractor
{
    public function __construct(
        private TemplateDrivenFireSuppressionMatrixExtractor $matrixExtractor,
        private FireSuppressionResultMerger $merger,
    ) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        $camelotResult = $this->matrixExtractor->extract($pdfPath, $semantic);
        return $this->merger->merge($camelotResult, $semantic);
    }
}
