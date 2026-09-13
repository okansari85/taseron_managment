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

            // Smalot/PdfParser getDataTm() returns:
            // [ [text-matrix, text], ... ]
            // not a text => matrix map. The text matrix contains X/Y at
            // indexes 4/5. See the library's documented return shape.
            foreach ($page->getDataTm() as $item) {
                if (!is_array($item) || count($item) < 2) continue;

                $matrix = $item[0] ?? null;
                $value = trim((string)($item[1] ?? ''));
                if (!is_array($matrix) || count($matrix) < 6 || $value === '') continue;

                $words[] = [
                    'text' => $value,
                    'x' => (float)($matrix[4] ?? 0),
                    'y' => (float)($matrix[5] ?? 0),
                    'width' => $this->estimateWidth($value, $matrix, $item),
                    'height' => $this->estimateHeight($matrix, $item),
                ];
            }

            $pages[] = [
                'page' => $pageNumber + 1,
                'words' => $words,
            ];
        }

        return $pages;
    }

    private function estimateWidth(string $text, array $matrix, array $item): float
    {
        $scale = abs((float)($matrix[0] ?? 1));
        $fontSize = isset($item[3]) && is_numeric($item[3])
            ? abs((float)$item[3])
            : abs((float)($matrix[3] ?? 1));

        return max(
            1.0,
            mb_strlen($text, 'UTF-8') * max(0.1, $scale) * max(1.0, $fontSize) * 0.55
        );
    }

    private function estimateHeight(array $matrix, array $item): float
    {
        $fontSize = isset($item[3]) && is_numeric($item[3])
            ? abs((float)$item[3])
            : abs((float)($matrix[3] ?? 1));

        return max(1.0, $fontSize);
    }
}
