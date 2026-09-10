<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\AnalyzeReportFileRequest;
use App\Http\Requests\StoreFireSuppressionReportRequest;
use App\Jobs\AnalyzeFireSuppressionReportJob;
use App\Models\FireSuppressionReport;
use App\Models\LocationBusinessEntity;
use App\Services\Ai\FireSuppressionAnalysisProgress;
use App\Services\FireSuppressionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FireSuppressionReportController extends Controller
{
    public function __construct(
        private FireSuppressionReportService $service
    ) {
    }

    // AI destekli ön-analiz — hiçbir şey kaydetmez, sadece taslak döner.
    // Kullanıcı taslağı gözden geçirip düzenledikten sonra store()'a
    // (değişmemiş) gönderir (section 12: Kullanıcı Onayı).
    //
    // ÖNEMLİ MİMARİ NOKTA: bu iş artık İSTEK İÇİNDE ÇALIŞMIYOR — dosyayı
    // saklayıp AnalyzeFireSuppressionReportJob'ı KUYRUĞA atıp HEMEN
    // dönüyor. Sebep: NVIDIA NIM çağrıları birkaç dakika sürebiliyor; bu
    // süre boyunca senkron çalışsaydı, yerel tek iş parçacıklı dev
    // sunucusu (php -S) o TEK isteğe kilitlenip DİĞER HER İSTEĞİ (login
    // dahil) bloklardı — gerçekten yaşanan bir sorundu. Ayrıca progress
    // polling'in de bir anlamı kalmazdı (sunucu zaten aynı isteğe
    // kilitliyken ilerleme sorgusu cevap bulamıyordu). Gerçek iş artık
    // ayrı bir worker sürecinde (php artisan queue:work) yürütülüyor.
    //
    // Pipeline: PdfTextExtractor → FireSuppressionOptimizedReportParser
    // (sayfa yönlendirme + mevcut parser) → MatchingEngine +
    // FireSuppressionMatchingProfile — hepsi artık Job::handle() içinde.
    public function analyze(
        AnalyzeReportFileRequest $request,
        LocationBusinessEntity $locationBusinessEntity,
        TenantContext $tenantContext,
        FireSuppressionAnalysisProgress $progress
    ): JsonResponse {
        $analysisId = (string) ($request->header('X-Analysis-Id') ?: Str::uuid());
        $file = $request->file('file');

        $storedPath = $file->store('fire-suppression-analysis-tmp', 'local');

        $progress->start($analysisId, 0);

        AnalyzeFireSuppressionReportJob::dispatch(
            $analysisId,
            $storedPath,
            $file->getClientOriginalName(),
            $locationBusinessEntity->id,
            $tenantContext->id(),
        );

        return response()->json(['analysis_id' => $analysisId], 202);
    }

    public function analysisProgress(string $analysisId, FireSuppressionAnalysisProgress $progress): JsonResponse
    {
        $state = $progress->get($analysisId);

        if ($state === null) {
            return response()->json(['message' => 'Analiz ilerleme kaydı bulunamadı.'], 404);
        }

        return response()->json(['data' => $state]);
    }

    public function index(LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all($locationBusinessEntity),
        ]);
    }

    public function show(FireSuppressionReport $fireSuppressionReport): JsonResponse
    {
        return response()->json([
            'data' => $this->service->find($fireSuppressionReport),
        ]);
    }

    public function store(
        StoreFireSuppressionReportRequest $request,
        LocationBusinessEntity $locationBusinessEntity
    ): JsonResponse {
        $validated = $request->validated();
        $additionalFiles = collect($validated['additional_files'] ?? [])
            ->map(fn (array $entry, int $index) => [
                'file' => $request->file("additional_files.{$index}.file"),
                'type' => $entry['type'],
                'description' => $entry['description'] ?? null,
            ])
            ->all();

        $report = $this->service->create(
            $locationBusinessEntity,
            $validated,
            $request->file('file'),
            $request->user(),
            $additionalFiles
        );

        return response()->json([
            'message' => 'Rapor başarıyla yüklendi.',
            'data' => $report,
        ], 201);
    }

    // Rapor yükleme sihirbazının "Kontrol ve Onay" adımında kullanılacak
    // standart checklist — kullanıcı seçtiği kategorilere göre filtrelenir.
    public function controlItemTemplates(Request $request): JsonResponse
    {
        $categories = array_filter((array) $request->query('categories', []));

        return response()->json([
            'data' => $this->service->controlItemTemplates($categories),
        ]);
    }

    public function destroy(FireSuppressionReport $fireSuppressionReport): JsonResponse
    {
        $this->service->delete($fireSuppressionReport);

        return response()->json([
            'message' => 'Rapor silindi.',
        ]);
    }
}
