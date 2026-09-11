<?php

namespace App\Jobs;

use App\Domain\Tenancy\TenantContext;
use App\Models\FireSuppressionInventoryItem;
use App\Models\LocationBusinessEntity;
use App\Models\Tenant;
use App\Services\Ai\FireSuppressionAiReportAnalyzer;
use App\Services\Ai\FireSuppressionAnalysisProgress;
use App\Services\Ai\PdfTextExtractor;
use App\Services\Matching\FireSuppressionMatchingProfile;
use App\Services\Matching\MatchingEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Rapor analizi (PDF çıkarma + TEK NVIDIA NIM isteği + eşleştirme) artık
// İSTEK İÇİNDE SENKRON çalışmıyor. Controller Job'ı kuyruğa atıp HEMEN döner.
class AnalyzeFireSuppressionReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        private readonly string $analysisId,
        private readonly string $storedFilePath,
        private readonly string $originalFileName,
        private readonly int $locationBusinessEntityId,
        private readonly int $tenantId,
    ) {
    }

    public function handle(
        PdfTextExtractor $extractor,
        FireSuppressionAiReportAnalyzer $analyzer,
        MatchingEngine $matchingEngine,
        FireSuppressionMatchingProfile $matchingProfile,
        FireSuppressionAnalysisProgress $progress,
        TenantContext $tenantContext
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $tenantContext->set($tenant);

        $locationBusinessEntity = LocationBusinessEntity::query()->findOrFail($this->locationBusinessEntityId);

        try {
            $absolutePath = Storage::disk('local')->path($this->storedFilePath);
            $file = new UploadedFile($absolutePath, $this->originalFileName, null, null, true);

            $progress->stage($this->analysisId, 'extracting', 'PDF metni çıkarılıyor');
            $pages = $extractor->extractPages($file);
            $progress->stage($this->analysisId, 'ai', 'Rapor tek AI isteğiyle analiz ediliyor');
            $draft = $analyzer->analyze($pages);
            $progress->stage($this->analysisId, 'matching', 'Envanter ile eşleştiriliyor');
        } catch (Throwable $exception) {
            report($exception);
            $progress->fail($this->analysisId, $exception->getMessage());
            $this->cleanup();

            return;
        }

        $candidateIds = [];

        $draft['equipment'] = array_map(function (array $item) use ($matchingEngine, $matchingProfile, $locationBusinessEntity, &$candidateIds) {
            $match = $matchingEngine->match($matchingProfile, $locationBusinessEntity, $item);
            $candidateIds = [...$candidateIds, ...($match['candidate_ids'] ?? [])];
            $item['match'] = $match;

            return $item;
        }, $draft['equipment'] ?? []);

        $exactIds = array_values(array_unique(array_filter(array_map(
            fn (array $item) => ($item['match']['status'] ?? null) === 'exact' ? ($item['match']['matched_id'] ?? null) : null,
            $draft['equipment']
        ))));

        $candidateIds = array_values(array_unique(array_filter($candidateIds)));

        $matchedInventoryItems = FireSuppressionInventoryItem::query()
            ->whereIn('id', $exactIds)
            ->get()
            ->map(fn (FireSuppressionInventoryItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'category' => $item->category,
                'location_note' => $item->location_note,
            ])
            ->values()
            ->all();

        $candidateInventoryItems = FireSuppressionInventoryItem::query()
            ->whereIn('id', $candidateIds)
            ->get()
            ->map(fn (FireSuppressionInventoryItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'category' => $item->category,
                'location_note' => $item->location_note,
            ])
            ->values()
            ->all();

        $unmatchedCodes = array_values(array_filter(array_map(
            fn (array $item) => ($item['match']['status'] ?? null) === 'new' ? ($item['code'] ?? null) : null,
            $draft['equipment']
        )));

        // Frontend'in beklediği düz sonuç şekli korunuyor.
        $result = $draft;
        // GEÇİCİ DEBUG: normalize edilmemiş Gemini çıktısını ayrıca result içine koy.
        $result['ai_raw_result'] = $draft;
        $result['matched_inventory_items'] = $matchedInventoryItems;
        $result['candidate_inventory_items'] = $candidateInventoryItems;
        $result['unmatched_codes'] = $unmatchedCodes;

        $progress->completeWithResult($this->analysisId, $result, [
            'counts' => [
                'equipment' => count($draft['equipment'] ?? []),
                'findings' => count($draft['findings'] ?? []),
                'matched' => count($matchedInventoryItems),
                'candidates' => count($candidateInventoryItems),
                'unmatched' => count($unmatchedCodes),
            ],
        ]);

        $this->cleanup();
    }

    private function cleanup(): void
    {
        try {
            Storage::disk('local')->delete($this->storedFilePath);
        } catch (Throwable) {
            // Cleanup failure must not mask the analysis result/error.
        }
    }
}