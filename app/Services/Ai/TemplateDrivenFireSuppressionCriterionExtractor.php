<?php

namespace App\Services\Ai;

/**
 * Backward-compatible orchestration entry point.
 *
 * Gemini remains the semantic authority. Camelot is used for equipment and
 * inspection results; the overall fallback runs only when Gemini/Camelot
 * produced no overall result.
 */
class TemplateDrivenFireSuppressionCriterionExtractor
{
    public function __construct(
        private TemplateDrivenFireSuppressionMatrixExtractor $matrixExtractor,
        private FireSuppressionResultMerger $merger,
        private FireSuppressionOverallResultFallback $overallFallback,
    ) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        $camelotResult = $this->matrixExtractor->extract($pdfPath, $semantic);
        $final = $this->merger->merge($camelotResult, $semantic);

        $overall = $final['extracted_data']['overall_result'] ?? null;
        if ($overall === null || $overall === [] || $overall === '') {
            $fallback = $this->overallFallback->extract($pdfPath);
            if ($fallback !== []) {
                $final['extracted_data']['overall_result'] = $fallback;
                $final['extracted_data']['report']['overall_result'] = $fallback;
            }
        }

        return $final;
    }
}
