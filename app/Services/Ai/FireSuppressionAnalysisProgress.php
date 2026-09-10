<?php

namespace App\Services\Ai;

use App\Models\FireSuppressionAnalysis;

/**
 * Progress state for the report-analysis UI — persisted in the DB
 * (fire_suppression_analyses) so the web process (progress polling) and
 * the queue worker process (actual analysis) reliably see the SAME state
 * regardless of which process/cache-config resolved it. Bu sınıfın PUBLIC
 * arayüzü (start/stage/complete/completeWithResult/fail/get, imzalar ve
 * döndürülen array şekli) kasıtlı olarak DEĞİŞTİRİLMEDİ — sadece get()/
 * write() içindeki depolama Cache'ten DB'ye taşındı (bkz. key(), artık
 * kullanılmıyor çünkü DB satırı analysis_id koluyla aranıyor).
 */
class FireSuppressionAnalysisProgress
{
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

    // Job tamamlandığında, frontend'in aynı polling isteğinden (analiz
    // sonucu için AYRI bir endpoint'e gerek kalmadan) taslağı da
    // alabilmesi için sonuç bu state'in içine gömülür.
    public function completeWithResult(string $analysisId, array $result, array $details = []): void
    {
        $state = $this->get($analysisId) ?? ['events' => []];
        $now = now()->toIso8601String();
        $state['status'] = 'completed';
        $state['current_stage'] = 'completed';
        $state['current_label'] = 'Analiz tamamlandı';
        $state['current_page'] = null;
        $state['finished_at'] = $now;
        $state['result'] = $result;
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
        $record = FireSuppressionAnalysis::query()->where('analysis_id', $analysisId)->first();

        if ($record === null) {
            return null;
        }

        return [
            'status' => $record->status,
            'current_stage' => $record->current_stage,
            'current_label' => $record->current_label,
            'current_page' => $record->current_page,
            'total_pages' => $record->total_pages,
            'started_at' => $record->started_at,
            'finished_at' => $record->finished_at,
            'error' => $record->error,
            'result' => $record->result,
            'events' => $record->events ?? [],
        ];
    }

    // ÖNEMLİ: eşleşme anahtarı SADECE analysis_id — updateOrCreate'in ikinci
    // argümanı ($state'ten türetilen TÜM alanlar) her çağrıda satırın
    // TAMAMINI günceller. Bu güvenlidir çünkü $state zaten her public
    // metotta ÖNCE $this->get() ile OKUNUP sonra mutasyona uğratılıyor
    // (bkz. stage/complete/fail yukarıda) — yani events/result burada asla
    // yanlışlıkla eski haliyle ezilmez, her zaman en güncel/birleşik hali
    // yazılır (eski Cache::put ile birebir aynı "tam değiştirme" semantiği).
    private function write(string $analysisId, array $state): void
    {
        FireSuppressionAnalysis::query()->updateOrCreate(
            ['analysis_id' => $analysisId],
            [
                'status' => $state['status'] ?? 'running',
                'current_stage' => $state['current_stage'] ?? null,
                'current_label' => $state['current_label'] ?? null,
                'current_page' => $state['current_page'] ?? null,
                'total_pages' => $state['total_pages'] ?? null,
                'started_at' => $state['started_at'] ?? null,
                'finished_at' => $state['finished_at'] ?? null,
                'error' => $state['error'] ?? null,
                'result' => $state['result'] ?? null,
                'events' => $state['events'] ?? [],
            ]
        );
    }
}
