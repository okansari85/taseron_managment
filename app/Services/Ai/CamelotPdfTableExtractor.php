<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class CamelotPdfTableExtractor
{
    public function extract(string $pdfPath): array
    {
        if (!is_file($pdfPath)) {
            throw new RuntimeException('Camelot PDF bulunamadı: ' . $pdfPath);
        }

        $python = (string) config('services.camelot.python', env('CAMELOT_PYTHON', 'python'));
        $script = base_path('scripts/camelot_extract.py');
        if (!is_file($script)) {
            throw new RuntimeException('Camelot scripti bulunamadı: ' . $script);
        }

        $command = [$python, $script, $pdfPath];
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        Log::info('Camelot debug: process starting');
        $process = proc_open($escaped, $descriptor, $pipes, base_path());
        if (!is_resource($process)) {
            Log::error('Camelot debug: process could not start');
            throw new RuntimeException('Camelot Python process başlatılamadı.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        Log::info('Camelot debug: process finished', [
            'exit_code' => $exitCode,
            'stdout_bytes' => strlen($stdout),
            'stderr_bytes' => strlen($stderr),
            'stdout_utf8' => mb_check_encoding($stdout, 'UTF-8'),
            'stderr_utf8' => mb_check_encoding($stderr, 'UTF-8'),
        ]);

        if ($exitCode !== 0) {
            Log::error('Camelot debug: process failed', [
                'stderr_preview' => $this->utf8Preview($stderr),
                'stdout_preview' => $this->utf8Preview($stdout),
            ]);
            throw new RuntimeException('Camelot başarısız: ' . $this->utf8Preview($stderr ?: $stdout));
        }

        $decoded = json_decode($stdout, true);
        Log::info('Camelot debug: json decode', [
            'json_error' => json_last_error_msg(),
            'decoded_array' => is_array($decoded),
        ]);

        if (!is_array($decoded)) {
            Log::error('Camelot debug: invalid JSON', [
                'stdout_preview' => $this->utf8Preview($stdout),
            ]);
            throw new RuntimeException('Camelot geçerli JSON döndürmedi: ' . $this->utf8Preview($stdout));
        }
        if (!empty($decoded['error'])) {
            Log::error('Camelot debug: python returned error', [
                'error' => $this->utf8Preview((string) $decoded['error']),
            ]);
            throw new RuntimeException('Camelot: ' . $this->utf8Preview((string) $decoded['error']));
        }

        Log::info('Camelot debug: extraction decoded successfully', [
            'table_count' => count((array) ($decoded['tables'] ?? [])),
        ]);

        return $decoded;
    }

    private function utf8Preview(string $value, int $length = 1000): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1254');
        }

        return mb_substr(trim($value), 0, $length);
    }
}
