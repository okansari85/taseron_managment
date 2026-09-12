<?php

namespace App\Jobs;

use App\Domain\Tenancy\TenantContext;
use App\Models\LocationBusinessEntity;
use App\Models\Tenant;
use App\Services\Ai\FireSuppressionAiReportAnalyzer;
use App\Services\Ai\FireSuppressionAnalysisProgress;
use App\Services\Ai\PdfTextExtractor;
use App\Services\Ai\UniversalFireSuppressionTableAnalyzer;
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

// Rapor analizi queue worker içinde çalışır: PDF çıkarma + tek Gemini isteği
// + deterministic universal table analysis.
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
        UniversalFireSuppressionTableAnalyzer $tableAnalyzer,
        MatchingEngine $matchingEngine,
        FireSuppressionMatchingProfile $matchingProfile,
        FireSuppressionAnalysisProgress $progress,
        TenantContext $tenantContext
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $tenantContext->set($tenant);
        LocationBusinessEntity::query()->findOrFail($this->locationBusinessEntityId);

        try {
            $absolutePath = Storage::disk('local')->path($this->storedFilePath);
            $file = new UploadedFile($absolutePath, $this->originalFileName, null, null, true);

            $progress->stage($this->analysisId, 'extracting', 'PDF metni çıkarılıyor');
            $pages = $extractor->extractPages($file);

            $progress->stage($this->analysisId, 'ai', 'Rapor tek AI isteğiyle analiz ediliyor');
            $semantic = $analyzer->analyze($pages);

            $progress->stage($this->analysisId, 'tables', 'Rapor tabloları dinamik olarak analiz ediliyor');
            $tables = $tableAnalyzer->analyze($pages, $semantic);

            // Frontend tek bir nihai JSON görür. Gemini'nin rapor/sistem/bulgu
            // semantiği korunur; ekipman ve teknik tablo verisi deterministic
            // analyzer tarafından aynı JSON'a eklenir.
            $draft = array_merge($semantic, [
                'systems' => $tables['systems'],
                'equipment' => $tables['equipment'],
                'control_matrix' => $tables['control_matrix'],
                'tables' => $tables['tables'],
                'analyzer' => $tables['analyzer'],
            ]);

            $progress->completeWithResult($this->analysisId, $draft, [
                'counts' => [
                    'systems' => count($draft['systems'] ?? []),
                    'findings' => count($draft['findings'] ?? []),
                    'equipment' => count($draft['equipment'] ?? []),
                    'controls' => count($draft['control_matrix'] ?? []),
                    'tables' => (int) ($draft['analyzer']['table_count'] ?? 0),
                ],
            ]);

            $this->cleanup();
        } catch (Throwable $exception) {
            report($exception);
            $progress->fail($this->analysisId, $exception->getMessage());
            $this->cleanup();
        }
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
