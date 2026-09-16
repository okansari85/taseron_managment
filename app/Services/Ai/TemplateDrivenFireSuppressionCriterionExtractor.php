<?php

namespace App\Services\Ai;

/**
 * Backward-compatible orchestration entry point.
 *
 * Gemini remains the semantic authority. Camelot supplies equipment and
 * inspection results; the standard-result pass fixes paired control rows
 * before the final merger runs.
 */
class TemplateDrivenFireSuppressionCriterionExtractor
{
    public function __construct(
        private TemplateDrivenFireSuppressionMatrixExtractor $matrixExtractor,
        private FireSuppressionStandardResultExtractor $standardResultExtractor,
        private FireSuppressionResultMerger $merger,
        private FireSuppressionOverallResultFallback $overallFallback,
    ) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        $camelotResult = $this->matrixExtractor->extract($pdfPath, $semantic);
        $camelotResult = $this->standardResultExtractor->apply($pdfPath, $semantic, $camelotResult);
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
