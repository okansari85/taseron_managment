<?php
namespace App\Jobs;

use App\Domain\Tenancy\TenantContext;
use App\Models\FireSuppressionInventoryItem;
use App\Models\LocationBusinessEntity;
use App\Models\Tenant;
use App\Services\Ai\FireSuppressionAnalysisProgress;
use App\Services\Ai\PdfTextExtractor;
use App\Services\Ai\TemplateDiscoveryFireSuppressionAnalyzer;
use App\Services\Ai\TemplateDiscoveryReportNormalizer;
use App\Services\Ai\TemplateDrivenFireSuppressionCriterionExtractor;
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
    ) {}

    public function handle(
        PdfTextExtractor $extractor,
        TemplateDiscoveryFireSuppressionAnalyzer $analyzer,
        TemplateDiscoveryReportNormalizer $normalizer,
        TemplateDrivenFireSuppressionCriterionExtractor $reportExtractor,
        MatchingEngine $matchingEngine,
        FireSuppressionMatchingProfile $matchingProfile,
        FireSuppressionAnalysisProgress $progress,
        TenantContext $tenantContext
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $tenantContext->set($tenant);
        $branch = LocationBusinessEntity::query()->findOrFail($this->locationBusinessEntityId);

        try {
            $absolutePath = Storage::disk('local')->path($this->storedFilePath);
            $file = new UploadedFile($absolutePath, $this->originalFileName, null, null, true);

            $progress->stage($this->analysisId, 'extracting', 'PDF metni çıkarılıyor');
            $pages = $extractor->extractPages($file);

            $progress->stage($this->analysisId, 'ai', 'Template Discovery ile rapor yapısı analiz ediliyor');
            $semantic = $analyzer->analyze($pages);

            // Tek extraction pipeline:
            // Gemini semantic -> code/criterion metadata
            // Camelot -> equipment/matrix/results
            // Merger -> unified fire_systems.control_items
            $absoluteReportPath = $absolutePath;
            $extracted = $reportExtractor->extract($absoluteReportPath, $semantic);
            $tables = $normalizer->normalize($extracted);

            $progress->stage($this->analysisId, 'ai_result', 'Template Discovery çıktısı hazır', null, [
                'ai_semantic' => $semantic,
            ]);

            $progress->stage($this->analysisId, 'tables', 'Hedef JSON ekipman eşleştirmesine hazırlanıyor');

            $candidateIds = [];
            $matchedIds = [];
            $matches = [];

            foreach ($tables['systems'] ?? [] as $systemIndex => $system) {
                foreach ($system['components'] ?? [] as $componentIndex => $equipment) {
                    $match = $matchingEngine->match($matchingProfile, $branch, $equipment);
                    $matches[$systemIndex][$componentIndex] = $match;
                    $candidateIds = array_merge($candidateIds, $match['candidate_ids'] ?? []);
                    if (!empty($match['matched_id'])) $matchedIds[] = $match['matched_id'];
                }
            }

            $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
            $matchedIds = array_values(array_unique(array_map('intval', $matchedIds)));
            $candidateMap = FireSuppressionInventoryItem::query()->whereIn('id', $candidateIds)->get()->keyBy('id');
            $matchedMap = FireSuppressionInventoryItem::query()->whereIn('id', $matchedIds)->get()->keyBy('id');

            $matchedInventory = [];
            $candidateInventory = [];
            $unmatched = [];

            foreach ($tables['systems'] ?? [] as $systemIndex => $system) {
                foreach ($system['components'] ?? [] as $componentIndex => $equipment) {
                    $match = $matches[$systemIndex][$componentIndex] ?? [
                        'status' => 'new',
                        'matched_id' => null,
                        'candidate_ids' => [],
                    ];

                    if (($match['status'] ?? '') === 'exact' && isset($match['matched_id'])) {
                        $matchedInventory[] = $matchedMap->get($match['matched_id']);
                    } elseif (($match['status'] ?? '') === 'candidate_single' && isset($match['candidate_ids'][0])) {
                        $matchedInventory[] = $candidateMap->get($match['candidate_ids'][0]);
                    } elseif (($match['status'] ?? '') === 'candidate_multiple') {
                        foreach ($match['candidate_ids'] ?? [] as $id) {
                            if ($candidateMap->has($id)) $candidateInventory[] = $candidateMap->get($id);
                        }
                    } elseif (!empty($equipment['code'])) {
                        $unmatched[] = $equipment['code'];
                    }
                }
            }

            $tables['matched_inventory_items'] = array_values(array_filter($matchedInventory));
            $tables['candidate_inventory_items'] = array_values(array_filter($candidateInventory));
            $tables['unmatched_codes'] = array_values(array_unique($unmatched));

            $progress->completeWithResult($this->analysisId, $tables, [
                'counts' => [
                    'systems' => count($tables['systems'] ?? []),
                    'findings' => count($tables['findings'] ?? []),
                    'equipment' => (int) ($tables['analyzer']['equipment_count'] ?? 0),
                    'controls' => (int) ($tables['analyzer']['control_count'] ?? 0),
                    'tables' => (int) ($tables['analyzer']['table_count'] ?? 0),
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
        }
    }
}
