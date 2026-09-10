<?php

namespace App\Jobs;

use App\Domain\Tenancy\TenantContext;
use App\Models\FireSuppressionInventoryItem;
use App\Models\LocationBusinessEntity;
use App\Models\Tenant;
use App\Services\Ai\FireSuppressionAnalysisProgress;
use App\Services\Ai\FireSuppressionOptimizedReportParser;
use App\Services\Ai\PdfTextExtractor;
use App\Services\Matching\FireSuppressionMatchingProfile;
use App\Services\Matching\MatchingEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Rapor analizi (PDF çıkarma + NVIDIA NIM + eşleştirme) artık İSTEK İÇİNDE
// SENKRON çalışmıyor — bu, tek iş parçacıklı yerel dev sunucusunda (php -S)
// birkaç dakika süren bir AI çağrısı boyunca DİĞER HER İSTEĞİ (login dahil)
// bloklamasına neden oluyordu ve progress polling'in de bir anlamı yoktu
// (sunucu zaten aynı isteğe kilitliyken ilerleme sorgusu cevap alamıyordu).
// Artık: controller bu Job'ı kuyruğa atıp HEMEN döner, gerçek iş ayrı bir
// worker sürecinde (php artisan queue:work) çalışır.
class AnalyzeFireSuppressionReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // NVIDIA NIM çağrıları (özellikle büyük/alışılmadık raporlarda) birkaç
    // dakika sürebiliyor — worker'ın varsayılan zaman aşımını (genelde 60sn)
    // aşmaması için açıkça yükseltiyoruz.
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
        FireSuppressionOptimizedReportParser $parser,
        MatchingEngine $matchingEngine,
        FireSuppressionMatchingProfile $matchingProfile,
        FireSuppressionAnalysisProgress $progress,
        TenantContext $tenantContext
    ): void {
        // Worker süreci, isteği yönlendiren ResolveTenant middleware'inden
        // habersizdir — TenantScope'un doğru çalışması (ve yanlış tenant'ın
        // verisini görmemesi) için burada elle kuruyoruz.
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $tenantContext->set($tenant);

        $locationBusinessEntity = LocationBusinessEntity::query()->findOrFail($this->locationBusinessEntityId);

        try {
            $absolutePath = Storage::disk('local')->path($this->storedFilePath);
            $file = new UploadedFile($absolutePath, $this->originalFileName, null, null, true);

            $progress->stage($this->analysisId, 'extracting', 'PDF metni çıkarılıyor');
            $pages = $extractor->extractPages($file);
            $progress->stage($this->analysisId, 'classifying', 'Sayfalar sınıflandırılıyor', null, [
                'total_pages' => count($pages),
            ]);

            $progress->stage($this->analysisId, 'ai', 'Rapor sayfaları analiz ediliyor');
            $draft = $parser->parse($pages);
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
            $candidateIds = [...$candidateIds, ...$match['candidate_ids']];
            $item['match'] = $match;

            return $item;
        }, $draft['equipment']);

        $exactIds = collect($draft['equipment'])->pluck('match.matched_id')->filter()->values()->all();
        $allReferencedIds = array_values(array_unique([...$exactIds, ...$candidateIds]));

        $referencedItems = $allReferencedIds === []
            ? new Collection()
            : FireSuppressionInventoryItem::query()->whereIn('id', $allReferencedIds)->get();

        $draft['matched_inventory_items'] = $referencedItems->whereIn('id', $exactIds)->values();
        $draft['candidate_inventory_items'] = $referencedItems->whereIn('id', $candidateIds)->values();
        $draft['unmatched_codes'] = collect($draft['equipment'])
            ->filter(fn (array $item) => $item['match']['status'] === 'new' && $item['code'])
            ->pluck('code')
            ->values()
            ->all();

        $progress->completeWithResult($this->analysisId, $draft, [
            'equipment_count' => count($draft['equipment']),
            'finding_count' => count($draft['findings'] ?? []),
        ]);

        $this->cleanup();
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
        app(FireSuppressionAnalysisProgress::class)->fail($this->analysisId, $exception->getMessage());
        $this->cleanup();
    }

    private function cleanup(): void
    {
        Storage::disk('local')->delete($this->storedFilePath);
    }
}
