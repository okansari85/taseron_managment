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
        $lines = [];

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

                $lines[] = trim(implode(' ', $row));
            }
        }

        $result = $this->scanLines($lines);

        // Some reports print the conclusion as a free paragraph on its own page,
        // never inside a Camelot-detected table at all (no ruled borders, no
        // stream-detected column structure) - the table scan above then has
        // nothing to find no matter how it's written. Fall back to the PDF's
        // raw text layer (pdftotext) in that case, scanning it the same way.
        if ($result === [] && $this->pdftotextAvailable()) {
            $rawText = $this->readRawText($pdfPath);
            if ($rawText !== null) {
                $lines = preg_split('/\r\n|\r|\n/u', $rawText) ?: [];
                $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
                $result = $this->scanLines($lines);
            }
        }

        return $result;
    }

    private function scanLines(array $lines): array
    {
        $parts = [];
        $active = false;
        $status = null;

        foreach ($lines as $text) {
            if ($this->isStart($text)) {
                $active = true;
                continue;
            }

            if (!$active) {
                continue;
            }

            if ($this->isEnd($text)) {
                break;
            }

            $parts[] = $text;

            if (preg_match('/UYGUN\s+DEĞİLDİR|UYGUN\s+DEGILDIR/iu', $text, $match) === 1) {
                $status = trim($match[0]);
            } elseif ($status === null && preg_match('/\bUYGUNDUR\b/iu', $text, $match) === 1) {
                $status = trim($match[0]);
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

    private function pdftotextAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $which = @shell_exec('command -v pdftotext 2>/dev/null') ?: @shell_exec('where pdftotext 2>NUL');
            $available = trim((string) $which) !== '';
        }
        return $available;
    }

    private function readRawText(string $pdfPath): ?string
    {
        $escaped = escapeshellarg($pdfPath);
        $output = @shell_exec("pdftotext -layout {$escaped} - 2>NUL") ?: @shell_exec("pdftotext -layout {$escaped} - 2>/dev/null");
        $output = is_string($output) ? trim($output) : '';
        return $output === '' ? null : $output;
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
