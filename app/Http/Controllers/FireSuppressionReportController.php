<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\AnalyzeReportFileRequest;
use App\Http\Requests\StoreFireSuppressionReportRequest;
use App\Jobs\AnalyzeFireSuppressionReportJob;
use App\Models\FireSuppressionReport;
use App\Models\LocationBusinessEntity;
use App\Services\Ai\CoordinatePdfWordExtractor;
use App\Services\Ai\FireSuppressionAiReportAnalyzer;
use App\Services\Ai\FireSuppressionAnalysisProgress;
use App\Services\Ai\PdfTextExtractor;
use App\Services\Ai\UniversalFireSuppressionTableAnalyzerV12;
use App\Services\FireSuppressionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;

class FireSuppressionReportController extends Controller
{
    public function __construct(private FireSuppressionReportService $service) {}

    public function analyze(AnalyzeReportFileRequest $request, LocationBusinessEntity $locationBusinessEntity, TenantContext $tenantContext, FireSuppressionAnalysisProgress $progress, PdfTextExtractor $extractor, FireSuppressionAiReportAnalyzer $analyzer): JsonResponse
    {
        if ($request->boolean('gemini_fixture')) {
            $file = $request->file('file');
            $pages = $extractor->extractPages($file);
            config(['services.fire_suppression.ai_provider' => 'gemini']);
            $semantic = $analyzer->analyze($pages);
            $fixtureId = (string) Str::uuid();
            $pdfPath = "fire-suppression-gemini-fixtures/{$fixtureId}.pdf";
            Storage::disk('local')->putFileAs('fire-suppression-gemini-fixtures', $file, "{$fixtureId}.pdf");
            $fixture = [
                'fixture_id' => $fixtureId,
                'provider' => 'gemini',
                'model' => config('services.gemini.text_model'),
                'original_file_name' => $file->getClientOriginalName(),
                'created_at' => now()->toIso8601String(),
                'pdf_path' => $pdfPath,
                'semantic' => $semantic,
            ];
            Storage::disk('local')->put("fire-suppression-gemini-fixtures/{$fixtureId}.json", json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return response()->json(['data' => $fixture]);
        }

        $analysisId = (string) ($request->header('X-Analysis-Id') ?: Str::uuid());
        $file = $request->file('file');
        $storedPath = $file->store('fire-suppression-analysis-tmp', 'local');
        $progress->start($analysisId, 0);
        AnalyzeFireSuppressionReportJob::dispatch($analysisId, $storedPath, $file->getClientOriginalName(), $locationBusinessEntity->id, $tenantContext->id());
        return response()->json(['analysis_id' => $analysisId], 202);
    }

    public function runGeminiFixtureV12(Request $request, PdfTextExtractor $extractor, CoordinatePdfWordExtractor $coordinateExtractor, UniversalFireSuppressionTableAnalyzerV12 $tableAnalyzer): JsonResponse
    {
        $fixtureId = trim((string) $request->input('fixture_id'));
        abort_if($fixtureId === '' || !preg_match('/^[0-9a-f-]{36}$/i', $fixtureId), 422, 'Geçersiz fixture ID.');

        $fixturePath = "fire-suppression-gemini-fixtures/{$fixtureId}.json";
        abort_unless(Storage::disk('local')->exists($fixturePath), 404, 'Gemini fixture bulunamadı.');
        $fixture = json_decode(Storage::disk('local')->get($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $pdfPath = (string) ($fixture['pdf_path'] ?? "fire-suppression-gemini-fixtures/{$fixtureId}.pdf");
        abort_unless(Storage::disk('local')->exists($pdfPath), 404, 'Fixture PDF bulunamadı.');

        $absolutePath = Storage::disk('local')->path($pdfPath);
        $file = new UploadedFile($absolutePath, $fixture['original_file_name'] ?? "{$fixtureId}.pdf", 'application/pdf', null, true);
        $pages = $extractor->extractPages($file);
        $coordinatePages = $coordinateExtractor->extract($absolutePath);
        $result = $tableAnalyzer->analyze($pages, (array) ($fixture['semantic'] ?? []), $coordinatePages);
        $result['fixture_id'] = $fixtureId;
        $result['analyzer']['fixture_mode'] = true;

        return response()->json(['data' => $result]);
    }

    public function analysisProgress(string $analysisId, FireSuppressionAnalysisProgress $progress): JsonResponse
    {
        $state = $progress->get($analysisId);
        if ($state === null) return response()->json(['message' => 'Analiz ilerleme kaydı bulunamadı.'], 404);
        return response()->json(['data' => $state]);
    }

    public function index(LocationBusinessEntity $locationBusinessEntity): JsonResponse { return response()->json(['data' => $this->service->all($locationBusinessEntity)]); }
    public function show(FireSuppressionReport $fireSuppressionReport): JsonResponse { return response()->json(['data' => $this->service->find($fireSuppressionReport)]); }

    public function store(StoreFireSuppressionReportRequest $request, LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        $validated = $request->validated();
        $additionalFiles = collect($validated['additional_files'] ?? [])->map(fn (array $entry, int $index) => [
            'file' => $request->file("additional_files.{$index}.file"),
            'type' => $entry['type'], 'description' => $entry['description'] ?? null,
        ])->all();
        $report = $this->service->create($locationBusinessEntity, $validated, $request->file('file'), $request->user(), $additionalFiles);
        return response()->json(['message' => 'Rapor başarıyla yüklendi.', 'data' => $report], 201);
    }

    public function controlItemTemplates(Request $request): JsonResponse
    {
        $categories = array_filter((array) $request->query('categories', []));
        return response()->json(['data' => $this->service->controlItemTemplates($categories)]);
    }

    public function destroy(FireSuppressionReport $fireSuppressionReport): JsonResponse
    {
        $this->service->delete($fireSuppressionReport);
        return response()->json(['message' => 'Rapor silindi.']);
    }
}
