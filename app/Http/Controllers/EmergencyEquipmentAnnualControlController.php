<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeReportFileRequest;
use App\Http\Requests\StoreEmergencyEquipmentAnnualControlReportRequest;
use App\Http\Requests\StoreYscAnnualControlFromAnalysisRequest;
use App\Models\EmergencyEquipmentAnnualControlReport;
use App\Models\LocationBusinessEntity;
use App\Models\LocationEmergencyEquipment;
use App\Services\Ai\PdfTextExtractor;
use App\Services\Ai\YscReportParser;
use App\Services\EmergencyEquipmentAnnualControlService;
use App\Services\Matching\MatchingEngine;
use App\Services\Matching\YscMatchingProfile;
use App\Services\YscAnnualControlSaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class EmergencyEquipmentAnnualControlController extends Controller
{
    public function __construct(
        private EmergencyEquipmentAnnualControlService $service
    ) {
    }

    // Tek upload akışının (fire-suppression analyze/review ekranı) "Kaydet"
    // adımı: rapor tipi AI tarafından "ysc" olarak sınıflandığında, aynı
    // table_shape/Camelot çıktısı (equipment + control_items, DÜZ diziler -
    // bkz. StoreFireSuppressionReportRequest ile AYNI şekil) buraya gelir ve
    // FireSuppressionReport yerine YSC alan modeline (LocationEmergencyEquipment
    // + EmergencyEquipmentAnnualControlReport) yazılır.
    public function storeFromAnalysis(
        StoreYscAnnualControlFromAnalysisRequest $request,
        LocationBusinessEntity $locationBusinessEntity,
        YscAnnualControlSaveService $saveService
    ): JsonResponse {
        $validated = $request->validated();
        $file = $request->hasFile('file') ? $request->file('file') : $this->uploadedFileFromFixture((string) $validated['fixture_id']);

        $report = $saveService->save(
            $locationBusinessEntity,
            $validated,
            $file,
            $request->user()
        );

        return response()->json([
            'message' => 'Yıllık kontrol raporu (YSC) kaydedildi.',
            'data' => $report,
        ], 201);
    }

    // Test modu: FireSuppressionReportController::uploadedFileFromFixture()
    // ile AYNI desen - gerçek dosya yoksa, zaten sunucuda duran bir Gemini
    // fixture'ının PDF'i sahte bir tarayıcı yükleme turu gerektirmeden
    // doğrudan kullanılır.
    private function uploadedFileFromFixture(string $fixtureId): UploadedFile
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $fixtureId) === 1, 422, 'Geçersiz fixture ID.');
        $fixturePath = "fire-suppression-gemini-fixtures/{$fixtureId}.json";
        abort_unless(Storage::disk('local')->exists($fixturePath), 404, 'Gemini fixture bulunamadı.');
        $fixture = json_decode(Storage::disk('local')->get($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $pdfPath = (string) ($fixture['pdf_path'] ?? "fire-suppression-gemini-fixtures/{$fixtureId}.pdf");
        abort_unless(Storage::disk('local')->exists($pdfPath), 404, 'Bu fixture için kayıtlı PDF yok.');
        $fileName = (string) ($fixture['original_file_name'] ?? 'rapor.pdf');

        return new UploadedFile(Storage::disk('local')->path($pdfPath), $fileName, 'application/pdf', null, true);
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
