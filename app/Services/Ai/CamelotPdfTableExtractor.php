<?php

namespace App\Services\Ai;

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

        $process = proc_open($escaped, $descriptor, $pipes, base_path());
        if (!is_resource($process)) {
            throw new RuntimeException('Camelot Python process başlatılamadı.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException('Camelot başarısız: ' . trim($stderr ?: $stdout));
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Camelot geçerli JSON döndürmedi: ' . substr(trim($stdout), 0, 500));
        }
        if (!empty($decoded['error'])) {
            throw new RuntimeException('Camelot: ' . $decoded['error']);
        }

        return $decoded;
    }
}
