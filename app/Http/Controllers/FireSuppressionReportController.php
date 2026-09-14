<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\AnalyzeReportFileRequest;
use App\Http\Requests\StoreFireSuppressionReportRequest;
use App\Jobs\AnalyzeFireSuppressionReportJob;
use App\Models\FireSuppressionReport;
use App\Models\LocationBusinessEntity;
use App\Services\Ai\FireSuppressionAnalysisProgress;
use App\Services\Ai\PdfTextExtractor;
use App\Services\Ai\TemplateDiscoveryFireSuppressionAnalyzer;
use App\Services\Ai\TemplateDiscoveryReportNormalizer;
use App\Services\Ai\TemplateDrivenFireSuppressionExtractor;
use App\Services\FireSuppressionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FireSuppressionReportController extends Controller
{
    public function __construct(private FireSuppressionReportService $service) {}

    public function analyze(
        AnalyzeReportFileRequest $request,
        LocationBusinessEntity $locationBusinessEntity,
        TenantContext $tenantContext,
        FireSuppressionAnalysisProgress $progress,
        PdfTextExtractor $extractor,
        TemplateDiscoveryFireSuppressionAnalyzer $analyzer,
        TemplateDiscoveryReportNormalizer $normalizer,
        TemplateDrivenFireSuppressionExtractor $templateExtractor
    ): JsonResponse {
        if ($request->boolean('gemini_fixture_list')) {
            $items = collect(Storage::disk('local')->files('fire-suppression-gemini-fixtures'))
                ->filter(fn (string $path) => str_ends_with($path, '.json'))
                ->map(function (string $path) {
                    $fixture = json_decode(Storage::disk('local')->get($path), true);
                    $fixtureId = $fixture['fixture_id'] ?? pathinfo($path, PATHINFO_FILENAME);
                    $pdfPath = (string) ($fixture['pdf_path'] ?? "fire-suppression-gemini-fixtures/{$fixtureId}.pdf");
                    return [
                        'fixture_id' => $fixtureId,
                        'provider' => $fixture['provider'] ?? 'gemini',
                        'model' => $fixture['model'] ?? null,
                        'original_file_name' => $fixture['original_file_name'] ?? pathinfo($path, PATHINFO_FILENAME),
                        'created_at' => $fixture['created_at'] ?? null,
                        'pdf_available' => Storage::disk('local')->exists($pdfPath),
                    ];
                })
                ->sortByDesc('created_at')
                ->values();

            return response()->json(['data' => $items]);
        }

        if ($request->boolean('gemini_fixture_get')) {
            $fixtureId = trim((string) $request->input('fixture_id'));
            abort_unless($fixtureId !== '' && preg_match('/^[0-9a-f-]{36}$/i', $fixtureId), 422, 'Geçersiz fixture ID.');
            $fixturePath = "fire-suppression-gemini-fixtures/{$fixtureId}.json";
            abort_unless(Storage::disk('local')->exists($fixturePath), 404, 'Gemini fixture bulunamadı.');

            return response()->json([
                'data' => json_decode(Storage::disk('local')->get($fixturePath), true, 512, JSON_THROW_ON_ERROR),
            ]);
        }

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
            Storage::disk('local')->put(
                "fire-suppression-gemini-fixtures/{$fixtureId}.json",
                json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
            );
            return response()->json(['data' => $fixture]);
        }

        if ($request->boolean('gemini_fixture_camelot')) {
            $fixtureId = trim((string) $request->input('fixture_id'));
            abort_unless($fixtureId !== '' && preg_match('/^[0-9a-f-]{36}$/i', $fixtureId), 422, 'Geçersiz fixture ID.');

            $fixturePath = "fire-suppression-gemini-fixtures/{$fixtureId}.json";
            abort_unless(Storage::disk('local')->exists($fixturePath), 404, 'Gemini fixture bulunamadı.');
            $fixture = json_decode(Storage::disk('local')->get($fixturePath), true, 512, JSON_THROW_ON_ERROR);

            $pdfPath = (string) ($fixture['pdf_path'] ?? "fire-suppression-gemini-fixtures/{$fixtureId}.pdf");
            if (!Storage::disk('local')->exists($pdfPath)) {
                abort_unless($request->hasFile('file'), 422, 'Bu fixture için PDF kayıtlı değil. Aynı rapor PDF\'sini seçmelisin.');
                $file = $request->file('file');
                Storage::disk('local')->putFileAs('fire-suppression-gemini-fixtures', $file, "{$fixtureId}.pdf");
                $pdfPath = "fire-suppression-gemini-fixtures/{$fixtureId}.pdf";
            }

            $result = $templateExtractor->extract(
                Storage::disk('local')->path($pdfPath),
                (array) ($fixture['semantic'] ?? [])
            );
            $result['fixture_id'] = $fixtureId;
            $result['original_file_name'] = $fixture['original_file_name'] ?? null;
            return response()->json(['data' => $result], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if ($request->boolean('gemini_fixture_delete')) {
            $fixtureId = trim((string) $request->input('fixture_id'));
            abort_unless($fixtureId !== '' && preg_match('/^[0-9a-f-]{36}$/i', $fixtureId), 422, 'Geçersiz fixture ID.');
            $fixturePath = "fire-suppression-gemini-fixtures/{$fixtureId}.json";
            abort_unless(Storage::disk('local')->exists($fixturePath), 404, 'Gemini fixture bulunamadı.');
            $fixture = json_decode(Storage::disk('local')->get($fixturePath), true, 512, JSON_THROW_ON_ERROR);
            $pdfPath = (string) ($fixture['pdf_path'] ?? "fire-suppression-gemini-fixtures/{$fixtureId}.pdf");
            Storage::disk('local')->delete([$fixturePath, $pdfPath]);
            return response()->json(['message' => 'Gemini fixture silindi.']);
        }

        if ($request->boolean('gemini_fixture_v12')) {
            $fixtureId = trim((string) $request->input('fixture_id'));
            $fixturePath = "fire-suppression-gemini-fixtures/{$fixtureId}.json";
            abort_unless(Storage::disk('local')->exists($fixturePath), 404, 'Gemini fixture bulunamadı.');
            $fixture = json_decode(Storage::disk('local')->get($fixturePath), true, 512, JSON_THROW_ON_ERROR);

            // Legacy V12 test endpoint is intentionally kept for the existing frontend flow.
            $result = $normalizer->normalize((array) ($fixture['semantic'] ?? []), $fixtureId);
            $result['analyzer']['fixture_mode'] = true;
            return response()->json(['data' => $result]);
        }

        $analysisId = (string) ($request->header('X-Analysis-Id') ?: Str::uuid());
        $file = $request->file('file');
        $storedPath = $file->store('fire-suppression-analysis-tmp', 'local');
        $progress->start($analysisId, 0);
        AnalyzeFireSuppressionReportJob::dispatch($analysisId, $storedPath, $file->getClientOriginalName(), $locationBusinessEntity->id, $tenantContext->id());
        return response()->json(['analysis_id' => $analysisId], 202);
    }

    public function analysisProgress(string $analysisId, FireSuppressionAnalysisProgress $progress): JsonResponse
    {
        $state = $progress->get($analysisId);
        if ($state === null) return response()->json(['message' => 'Analiz ilerleme kaydı bulunamadı.'], 404);
        return response()->json(['data' => $state]);
    }

    public function index(LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        return response()->json(['data' => $this->service->all($locationBusinessEntity)]);
    }

    public function show(FireSuppressionReport $fireSuppressionReport): JsonResponse
    {
        return response()->json(['data' => $this->service->find($fireSuppressionReport)]);
    }

    public function store(StoreFireSuppressionReportRequest $request, LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        $validated = $request->validated();
        $additionalFiles = collect($validated['additional_files'] ?? [])->map(
            fn (array $entry, int $index) => [
                'file' => $request->file("additional_files.{$index}.file"),
                'type' => $entry['type'],
                'description' => $entry['description'] ?? null,
            ]
        )->all();
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
