<?php
namespace App\Services\Ai;

use Smalot\PdfParser\Parser;

/**
 * Extracts page words with their PDF coordinates for coordinate table analysis.
 * This is intentionally separate from the existing text extractor so existing
 * Gemini text analysis remains unchanged.
 */
class CoordinatePdfWordExtractor
{
    public function __construct(private readonly Parser $parser)
    {
    }

    public function extract(string $absolutePath): array
    {
        $pdf = $this->parser->parseFile($absolutePath);
        $pages = [];

        foreach ($pdf->getPages() as $pageNumber => $page) {
            $words = [];
            foreach ($page->getDataTm() as $text => $matrices) {
                foreach ((array)$matrices as $matrix) {
                    if (!is_array($matrix) || count($matrix) < 6) continue;
                    $x = (float)($matrix[4] ?? 0);
                    $y = (float)($matrix[5] ?? 0);
                    $value = trim((string)$text);
                    if ($value === '') continue;
                    $words[] = [
                        'text' => $value,
                        'x' => $x,
                        'y' => $y,
                        'width' => $this->estimateWidth($value, $matrix),
                        'height' => $this->estimateHeight($matrix),
                    ];
                }
            }

            $pages[] = [
                'page' => $pageNumber + 1,
                'words' => $words,
            ];
        }

        return $pages;
    }

    private function estimateWidth(string $text, array $matrix): float
    {
        $scale = abs((float)($matrix[0] ?? 0));
        return max(1.0, mb_strlen($text, 'UTF-8') * max(1.0, $scale) * 0.55);
    }

    private function estimateHeight(array $matrix): float
    {
        return max(1.0, abs((float)($matrix[3] ?? 0)));
    }
}
