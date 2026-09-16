<?php

namespace App\Services\Ai;

/**
 * Last-resort fallback for reports where Gemini did not return overall_result.
 * Gemini remains the preferred authority; this only prevents an empty final
 * result when the report has a clearly identifiable conclusion section.
 */
class FireSuppressionOverallResultFallback
{
    public function __construct(private CamelotPdfTableExtractor $camelot)
    {
    }

    public function extract(string $pdfPath): array
    {
        $payload = $this->camelot->extract($pdfPath);
        $parts = [];
        $active = false;
        $status = null;

        foreach ((array) ($payload['tables'] ?? []) as $table) {
            if (!is_array($table)) {
                continue;
            }

            foreach ((array) ($table['data'] ?? []) as $row) {
                $row = array_values(array_filter(
                    array_map(static fn ($value): string => trim((string) $value), (array) $row),
                    static fn (string $value): bool => $value !== ''
                ));
                if ($row === []) {
                    continue;
                }

                $text = trim(implode(' ', $row));
                if ($this->isStart($text)) {
                    $active = true;
                    continue;
                }

                if (!$active) {
                    continue;
                }

                if ($this->isEnd($text)) {
                    break 2;
                }

                $parts[] = $text;

                if (preg_match('/UYGUN\s+DEĞİLDİR|UYGUN\s+DEGILDIR/iu', $text, $match) === 1) {
                    $status = trim($match[0]);
                } elseif ($status === null && preg_match('/\bUYGUNDUR\b/iu', $text, $match) === 1) {
                    $status = trim($match[0]);
                }
            }
        }

        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
        if ($text === '' && $status === null) {
            return [];
        }

        $result = [];
        if ($text !== '') {
            $result['text'] = $text;
        }
        if ($status !== null) {
            $result['status'] = $status;
        }

        return $result;
    }

    private function isStart(string $text): bool
    {
        return preg_match('/(?:^|\s)\d+\.\s*SONUÇ\s*(?:VE\s*)?KANAAT/iu', $text) === 1
            || preg_match('/SONUÇ\s*(?:VE\s*)?KANAAT/iu', $text) === 1;
    }

    private function isEnd(string $text): bool
    {
        return preg_match('/^\d+\.\s+/u', $text) === 1
            || preg_match('/^Bu rapor\b/iu', $text) === 1
            || preg_match('/^OKCO\b/iu', $text) === 1;
    }
}
