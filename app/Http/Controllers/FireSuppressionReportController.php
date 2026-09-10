<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeReportFileRequest;
use App\Http\Requests\StoreFireSuppressionReportRequest;
use App\Models\FireSuppressionInventoryItem;
use App\Models\FireSuppressionReport;
use App\Models\LocationBusinessEntity;
use App\Services\Ai\FireSuppressionReportParser;
use App\Services\Ai\PdfTextExtractor;
use App\Services\FireSuppressionReportService;
use App\Services\Matching\FireSuppressionMatchingProfile;
use App\Services\Matching\MatchingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
    // Pipeline üç ayrı, birbirinden habersiz katmandan geçer:
    //   PdfTextExtractor (OCR/metin çıkarma — sağlayıcı değişebilir)
    //     → FireSuppressionReportParser (bizim kodumuz: normalize + doğrula + işaretle)
    //       → MatchingEngine + FireSuppressionMatchingProfile (kod → kesin, kategoriye özgü alanlar → aday)
    public function analyze(
        AnalyzeReportFileRequest $request,
        LocationBusinessEntity $locationBusinessEntity,
        PdfTextExtractor $extractor,
        FireSuppressionReportParser $parser,
        MatchingEngine $matchingEngine,
        FireSuppressionMatchingProfile $matchingProfile
    ): JsonResponse {
        try {
            $pages = $extractor->extractPages($request->file('file'));
            $draft = $parser->parse($pages);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
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

        // Geriye dönük uyumluluk: matched_inventory_items/unmatched_codes hâlâ
        // eskisi gibi dönüyor (sadece KESİN eşleşmeler + hiç adayı olmayanlar),
        // yeni "equipment[].match" alanı ise aday/belirsiz ayrımını taşıyor.
        $draft['matched_inventory_items'] = $referencedItems->whereIn('id', $exactIds)->values();
        $draft['candidate_inventory_items'] = $referencedItems->whereIn('id', $candidateIds)->values();
        $draft['unmatched_codes'] = collect($draft['equipment'])
            ->filter(fn (array $item) => $item['match']['status'] === 'new' && $item['code'])
            ->pluck('code')
            ->values()
            ->all();

        return response()->json(['data' => $draft]);
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
