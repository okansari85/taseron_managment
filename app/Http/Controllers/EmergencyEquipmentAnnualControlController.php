<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeReportFileRequest;
use App\Http\Requests\StoreEmergencyEquipmentAnnualControlReportRequest;
use App\Models\EmergencyEquipmentAnnualControlReport;
use App\Models\LocationBusinessEntity;
use App\Models\LocationEmergencyEquipment;
use App\Services\Ai\PdfTextExtractor;
use App\Services\Ai\YscReportParser;
use App\Services\EmergencyEquipmentAnnualControlService;
use App\Services\Matching\MatchingEngine;
use App\Services\Matching\YscMatchingProfile;
use Illuminate\Http\JsonResponse;

class EmergencyEquipmentAnnualControlController extends Controller
{
    public function __construct(
        private EmergencyEquipmentAnnualControlService $service
    ) {
    }

    // AI destekli ön-analiz — hiçbir şey kaydetmez, sadece taslak döner.
    //
    // Pipeline: PdfTextExtractor (OCR/metin çıkarma — sağlayıcı değişebilir)
    //   → YscReportParser (bizim kodumuz: normalize + doğrula + işaretle)
    //     → MatchingEngine + YscMatchingProfile (kod → kesin, tip+kapasite+konum → aday)
    public function analyze(
        AnalyzeReportFileRequest $request,
        LocationBusinessEntity $locationBusinessEntity,
        PdfTextExtractor $extractor,
        YscReportParser $parser,
        MatchingEngine $matchingEngine,
        YscMatchingProfile $matchingProfile
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

        $referencedEquipment = $allReferencedIds === []
            ? new \Illuminate\Support\Collection()
            : LocationEmergencyEquipment::query()->whereIn('id', $allReferencedIds)->with('equipmentType')->get();

        // Geriye dönük uyumluluk: matched_inventory_items/unmatched_codes hâlâ
        // eskisi gibi dönüyor (sadece KESİN eşleşmeler + hiç adayı olmayanlar),
        // yeni "equipment[].match" alanı ise aday/belirsiz ayrımını taşıyor.
        $draft['matched_inventory_items'] = $referencedEquipment->whereIn('id', $exactIds)->values();
        $draft['candidate_inventory_items'] = $referencedEquipment->whereIn('id', $candidateIds)->values();
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

    public function show(EmergencyEquipmentAnnualControlReport $annualControlReport): JsonResponse
    {
        return response()->json([
            'data' => $this->service->find($annualControlReport),
        ]);
    }

    public function store(
        StoreEmergencyEquipmentAnnualControlReportRequest $request,
        LocationBusinessEntity $locationBusinessEntity
    ): JsonResponse {
        $report = $this->service->create(
            $locationBusinessEntity,
            $request->validated(),
            $request->file('file'),
            $request->user()
        );

        return response()->json([
            'message' => 'Yıllık kontrol raporu yüklendi.',
            'data' => $report,
        ], 201);
    }

    public function destroy(EmergencyEquipmentAnnualControlReport $annualControlReport): JsonResponse
    {
        $this->service->delete($annualControlReport);

        return response()->json([
            'message' => 'Yıllık kontrol raporu silindi.',
        ]);
    }
}
