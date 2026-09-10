<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived progress state for the report-analysis UI.
 *
 * This is telemetry only: it does not alter the analysis result or persistence
 * flow. The state expires automatically so it cannot accumulate indefinitely.
 */
class FireSuppressionAnalysisProgress
{
    private const TTL_SECONDS = 3600;

    public function start(string $analysisId, int $totalPages): void
    {
        $this->write($analysisId, [
            'status' => 'running',
            'current_stage' => 'upload',
            'current_label' => 'PDF alındı',
            'total_pages' => $totalPages,
            'current_page' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'events' => [[
                'stage' => 'upload',
                'label' => 'PDF alındı',
                'status' => 'done',
                'at' => now()->toIso8601String(),
            ]],
        ]);
    }

    public function stage(string $analysisId, string $stage, string $label, ?int $page = null, array $details = []): void
    {
        $state = $this->get($analysisId) ?? [
            'status' => 'running',
            'events' => [],
        ];

        $now = now()->toIso8601String();
        $state['status'] = 'running';
        $state['current_stage'] = $stage;
        $state['current_label'] = $label;
        $state['current_page'] = $page;
        $state['events'][] = array_merge([
            'stage' => $stage,
            'label' => $label,
            'status' => 'running',
            'at' => $now,
        ], $details);

        $this->write($analysisId, $state);
    }

    public function complete(string $analysisId, array $details = []): void
    {
        $state = $this->get($analysisId) ?? ['events' => []];
        $now = now()->toIso8601String();
        $state['status'] = 'completed';
        $state['current_stage'] = 'completed';
        $state['current_label'] = 'Analiz tamamlandı';
        $state['current_page'] = null;
        $state['finished_at'] = $now;
        $state['events'][] = array_merge([
            'stage' => 'completed',
            'label' => 'Analiz tamamlandı',
            'status' => 'done',
            'at' => $now,
        ], $details);

        $this->write($analysisId, $state);
    }

    public function fail(string $analysisId, string $message): void
    {
        $state = $this->get($analysisId) ?? ['events' => []];
        $now = now()->toIso8601String();
        $state['status'] = 'failed';
        $state['current_stage'] = 'error';
        $state['current_label'] = 'Analiz başarısız oldu';
        $state['finished_at'] = $now;
        $state['error'] = $message;
        $state['events'][] = [
            'stage' => 'error',
            'label' => 'Analiz başarısız oldu',
            'status' => 'error',
            'at' => $now,
            'message' => $message,
        ];

        $this->write($analysisId, $state);
    }

    public function get(string $analysisId): ?array
    {
        $value = Cache::get($this->key($analysisId));

        return is_array($value) ? $value : null;
    }

    private function write(string $analysisId, array $state): void
    {
        Cache::put($this->key($analysisId), $state, self::TTL_SECONDS);
    }

    private function key(string $analysisId): string
    {
        return 'fire-suppression-analysis:' . $analysisId;
    }
}
