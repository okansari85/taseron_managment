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

// Rapor analizi (PDF çıkarma + TEK AI isteği) artık İSTEK İÇİNDE SENKRON çalışmıyor.
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

            // GEÇİCİ DOĞRULAMA MODU:
            // AI'nin Gemini'den dönen ham JSON'unu olduğu gibi frontend'e ver.
            // Normalize ve inventory matching bu modda bilinçli olarak atlanıyor.
            $progress->completeWithResult($this->analysisId, $draft, [
                'counts' => [
                    'systems' => count($draft['systems'] ?? []),
                    'findings' => count($draft['findings'] ?? []),
                ],
            ]);

            $this->cleanup();
            return;
        } catch (Throwable $exception) {
            report($exception);
            $progress->fail($this->analysisId, $exception->getMessage());
            $this->cleanup();

            return;
        }

        // Matching kodu normal çalışma modunda burada çalıştırılacaktır.
        // Geçici ham JSON doğrulama modunda yukarıdaki return nedeniyle ulaşılmaz.
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
