<?php

namespace App\Http\Controllers;

use App\Models\PkEquipment;
use App\Models\PkInspection;
use App\Services\Ai\PkTakip\PkInspectionReportReader;
use App\Services\Ai\PkTakip\PkReportFixtureStore;
use App\Services\PkEquipmentPropertyService;
use App\Services\PkBulkReportService;
use App\Services\PkEquipmentService;
use App\Services\PkReportAnalysisHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// pktakip uzman paneli "Ekipmanlar" ekranı.
class PkEquipmentController extends Controller
{
    // belirtilmemis: raporda var ama uygunluğu yazmıyor (tesisat raporundan gelen kontrol kaydı; düzenlemede korunur).
    private const STATUSES = ['uygun', 'uygun_degil', 'rapor_bekleniyor', 'belirtilmemis'];

    public function __construct(private PkEquipmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($request->validate([
            'location_id' => ['nullable', 'integer'],
            'workplace_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string'],
            'type_id' => ['nullable', 'integer'],
        ])));
    }

    public function show(PkEquipment $pkEquipment): JsonResponse
    {
        return response()->json($this->service->show($pkEquipment));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->equipmentRules() + [
            'location_id' => ['required', 'integer'],
            'equipment_type_id' => ['required', 'integer', Rule::exists('periodic_equipment_types', 'id')],
        ]);

        $equipment = $this->service->create($data, $request->user());

        return response()->json($this->service->show($equipment), 201);
    }

    public function update(Request $request, PkEquipment $pkEquipment): JsonResponse
    {
        $data = $request->validate($this->equipmentRules() + [
            'location_id' => ['sometimes', 'integer'],
            'equipment_type_id' => ['sometimes', 'integer', Rule::exists('periodic_equipment_types', 'id')],
        ]);

        return response()->json($this->service->show($this->service->update($pkEquipment, $data)));
    }

    public function destroy(PkEquipment $pkEquipment): JsonResponse
    {
        $this->service->delete($pkEquipment);

        return response()->json(['message' => 'Ekipman silindi.']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $count = $this->service->deleteMany($data['ids']);

        return response()->json(['message' => "{$count} ekipman silindi.", 'deleted' => $count]);
    }

    // Pasife alma (tek ya da toplu): tarih (varsayılan bugün), neden, not. Geçmiş kalır; aktife alınarak geri döner.
    public function bulkDeactivate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'deactivated_at' => ['nullable', 'date'],
            'reason' => ['required', Rule::in(PkEquipmentService::DEACTIVATION_REASONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'reason.required' => 'Pasife alma nedenini seçin.',
        ]);

        $count = $this->service->deactivate($data['ids'], $data);

        return response()->json(['message' => "{$count} ekipman pasife alındı.", 'updated' => $count]);
    }

    public function bulkActivate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $count = $this->service->activate($data['ids']);

        return response()->json(['message' => "{$count} ekipman aktife alındı.", 'updated' => $count]);
    }

    public function storePhoto(Request $request, PkEquipment $pkEquipment): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']]);

        return response()->json($this->service->show($this->service->setPhoto($pkEquipment, $request->file('photo'))));
    }

    public function destroyPhoto(PkEquipment $pkEquipment): JsonResponse
    {
        return response()->json($this->service->show($this->service->setPhoto($pkEquipment, null)));
    }

    // Multipart: uygun / uygun değil için "report" dosyası zorunlu; rapor_bekleniyor dosyasız.
    public function storeInspection(Request $request, PkEquipment $pkEquipment): JsonResponse
    {
        $data = $request->validate($this->inspectionRules());

        $this->service->addInspection($pkEquipment, $data, $request->user(), $request->file('report'));

        return response()->json($this->service->show($pkEquipment), 201);
    }

    // Yapay zeka ile rapor okuma: yalnızca öneri döner, kaydetmez. Senkron (Gemini 1-3 dk sürebilir).
    // fixture_id verilirse Gemini çağrılmaz: test sayfasında kaydedilmiş analiz kullanılır.
    public function analyzeReport(Request $request, PkEquipment $pkEquipment, PkInspectionReportReader $reader, PkReportFixtureStore $fixtures, PkReportAnalysisHistory $history): JsonResponse
    {
        $data = $request->validate([
            'report' => ['required_without:fixture_id', 'nullable', 'file', 'mimes:pdf', 'max:51200'],
            'fixture_id' => ['nullable', 'uuid'],
        ]);
        set_time_limit(600);
        // Analiz geçmişi: yapay zeka ile yapılan okuma (test verisiyle değil); geçmişe yazılamazsa okuma yine döner.
        $live = empty($data['fixture_id']);

        try {
            $pkEquipment->load(['type', 'workplace.businessEntity.company']);
            $result = !$live
                ? $reader->fromFixture($fixtures->get($data['fixture_id']), $pkEquipment)
                : $reader->read($request->file('report'), $pkEquipment);

            $fileHash = $request->hasFile('report') ? hash_file('sha256', $request->file('report')->getRealPath()) : null;
            $response = $this->service->rememberAnalysis($pkEquipment, $result, $request->user(), $fileHash);
            if ($live) {
                rescue(fn () => $history->recordRead($response['analysis_id'], 'equipment', $result, $request->file('report'), $fileHash, $request->user(), $pkEquipment->location_id, $pkEquipment->type?->name, $pkEquipment->id));
            }

            return response()->json($response);
        } catch (RuntimeException $exception) {
            if ($live) {
                rescue(fn () => $history->recordFailure('equipment', $request->file('report'), $exception->getMessage(), $request->user(), $pkEquipment->location_id, $pkEquipment->type?->name, $pkEquipment->id));
            }
            throw ValidationException::withMessages(['report' => 'Rapor okunamadı: ' . $exception->getMessage()]);
        }
    }

    // Rapordan gelmiş bir teknik özelliğin elle düzeltilmesi; sonraki raporlar bu değeri otomatik ezmez.
    public function correctProperty(Request $request, PkEquipment $pkEquipment, PkEquipmentPropertyService $properties): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'value' => ['required', 'string', 'max:1000'],
        ]);
        $properties->correct($pkEquipment, $data['key'], $data['value'], $request->user());

        return response()->json($this->service->show($pkEquipment));
    }

    // Teknik özelliği listeden kaldırır (geçmiş korunur); sonraki raporda gelirse eklensin mi diye sorulur.
    public function removeProperty(Request $request, PkEquipment $pkEquipment, string $key, PkEquipmentPropertyService $properties): JsonResponse
    {
        $properties->remove($pkEquipment, $key, $request->user());

        return response()->json($this->service->show($pkEquipment));
    }

    // Rapordan yeni ekipman: formdaki taslağa göre önizleme (ekipman oluşturulmaz).
    public function previewDraft(Request $request, string $analysisId): JsonResponse
    {
        $draft = $request->validate([
            'equipment_type_id' => ['required', 'integer', Rule::exists('periodic_equipment_types', 'id')],
            'variant' => ['nullable', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'place' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->service->previewDraft($analysisId, $draft, $request->user()));
    }

    // Rapordan yeni ekipman: ekipman + rapor birlikte kaydedilir.
    public function storeFromReport(Request $request): JsonResponse
    {
        $equipment = $request->validate($this->equipmentRules() + [
            'location_id' => ['required', 'integer'],
            'equipment_type_id' => ['required', 'integer', Rule::exists('periodic_equipment_types', 'id')],
        ]);
        $inspection = $request->validate($this->inspectionRules());

        $created = $this->service->createFromReport($equipment, $inspection, $request->user(), $request->file('report'));

        return response()->json($this->service->show($created), 201);
    }

    // Rapordan ekipman tanımlama: tür bilinmiyor, Gemini katalogdan seçer (fixture_id ile test verisi). Kaydetmez.
    // Rapor toplu tüp kontrol formuysa (bulk) yanıtta tüp satırları, bölümler ve lokasyondaki tüpler de gelir; kayıt pk-bulk-reports.
    public function analyzeNewReport(Request $request, PkInspectionReportReader $reader, PkReportFixtureStore $fixtures, PkReportAnalysisHistory $history, PkBulkReportService $bulk): JsonResponse
    {
        $data = $request->validate([
            'report' => ['required_without:fixture_id', 'nullable', 'file', 'mimes:pdf', 'max:51200'],
            'fixture_id' => ['nullable', 'uuid'],
            // Seçili çalışma alanının lokasyonu: seri no yokken aynı lokasyondaki aday seçili gelir.
            'location_id' => ['nullable', 'integer'],
        ]);
        set_time_limit(600);
        $live = empty($data['fixture_id']);
        $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;

        try {
            $result = !$live
                ? $reader->fromFixtureUnknown($fixtures->get($data['fixture_id']))
                : $reader->readUnknown($request->file('report'));
        } catch (RuntimeException $exception) {
            if ($live) {
                rescue(fn () => $history->recordFailure('equipment_new', $request->file('report'), $exception->getMessage(), $request->user(), $locationId));
            }
            throw ValidationException::withMessages(['report' => 'Rapor okunamadı: ' . $exception->getMessage()]);
        }
        $fileHash = $request->hasFile('report') ? hash_file('sha256', $request->file('report')->getRealPath()) : null;

        $response = $this->service->rememberNewAnalysis($result, $request->user(), $fileHash, $locationId);
        if (!empty($result['bulk'])) {
            $response += $bulk->rememberAnalysis($response['analysis_id'], $result, $locationId, $fileHash);
        }
        if ($live) {
            rescue(fn () => $history->recordRead($response['analysis_id'], !empty($result['bulk']) ? 'bulk' : 'equipment_new', $result, $request->file('report'), $fileHash, $request->user(), $locationId, $result['detected_type']['name'] ?? null));
        }

        return response()->json($response);
    }

    // Kayıtlı analizi (rapordan ekipman tanımlama) bu ekipmana bağlar; rapor penceresi bununla açılır.
    public function rebindAnalysis(Request $request, PkEquipment $pkEquipment, string $analysisId): JsonResponse
    {
        return response()->json($this->service->rebindAnalysis($analysisId, $pkEquipment, $request->user()));
    }

    public function destroyInspection(PkEquipment $pkEquipment, PkInspection $pkInspection): JsonResponse
    {
        // Tesisat raporundan gelen kontrol kaydı buradan silinmez; tesisat raporu silinince silinir.
        if ($pkInspection->pk_equipment_id === $pkEquipment->id && $pkInspection->installationReports()->exists()) {
            throw ValidationException::withMessages(['inspection' => "Bu kontrol kaydı tesisat raporundan gelir; Tesisatlar'da o kontrol silinince silinir."]);
        }
        // Tüp kontrol formundan gelen kayıt da buradan silinmez; form silinince silinir.
        if ($pkInspection->pk_equipment_id === $pkEquipment->id && $pkInspection->bulkReports()->exists()) {
            throw ValidationException::withMessages(['inspection' => 'Bu kontrol kaydı tüp kontrol formundan gelir; Kontrol Formları\'nda o form silinince silinir.']);
        }
        $this->service->deleteInspection($pkEquipment, $pkInspection);

        return response()->json($this->service->show($pkEquipment));
    }

    // Kontrol kaydını düzenleme: rapor dosyası ve yapay zeka analizi değişmez.
    public function updateInspection(Request $request, PkEquipment $pkEquipment, PkInspection $pkInspection): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'control_date' => ['required', 'date'],
            'next_control_date' => ['nullable', 'date', 'after_or_equal:control_date'],
            'report_no' => ['nullable', 'string', 'max:100'],
            'inspection_body' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'conclusion' => ['nullable', 'string'],
            'findings' => ['nullable', 'array', 'max:500'],
            'findings.*' => ['nullable', 'string', 'max:5000'],
        ], [
            'control_date.required' => 'Kontrol tarihi zorunludur.',
            'next_control_date.after_or_equal' => 'Gelecek kontrol tarihi kontrol tarihinden önce olamaz.',
        ]);

        $this->service->updateInspection($pkEquipment, $pkInspection, $data);

        return response()->json($this->service->show($pkEquipment));
    }

    // Yapay zeka özetinden: raporun katalogda olmayan bir özelliğinin kataloğa eklenmesi için talep.
    public function requestSpec(Request $request, PkEquipment $pkEquipment, PkInspection $pkInspection, PkEquipmentPropertyService $properties): JsonResponse
    {
        abort_if($pkInspection->pk_equipment_id !== $pkEquipment->id, 404);
        $data = $request->validate([
            'id' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
        ]);
        $properties->requestFromInspection($pkEquipment, $pkInspection, $data['id'], trim($data['name']), filled($data['unit'] ?? null) ? trim($data['unit']) : null, $request->user());

        return response()->json($this->service->show($pkEquipment));
    }

    public function downloadReport(PkEquipment $pkEquipment, PkInspection $pkInspection): BinaryFileResponse
    {
        [$path, $name] = $this->service->reportFile($pkEquipment, $pkInspection);

        return response()->file($path, ['Content-Disposition' => 'inline; filename="' . addslashes($name) . '"']);
    }

    // Kontrol kaydı (rapor) doğrulaması: kontrol ekleme ve rapordan yeni ekipman için ortak.
    private function inspectionRules(): array
    {
        return [
            'status' => ['required', Rule::in(self::STATUSES)],
            'control_date' => ['nullable', 'date'],
            'next_control_date' => ['nullable', 'date', 'after_or_equal:control_date'],
            'note' => ['nullable', 'string'],
            'inspection_body' => ['nullable', 'string', 'max:255'],
            'apply_variant' => ['nullable', 'boolean'],
            'apply_code' => ['nullable', 'boolean'],
            'conclusion' => ['nullable', 'string'],
            'findings' => ['nullable', 'array', 'max:500'],
            'findings.*' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', Rule::in(['manual', 'ai'])],
            'analysis_id' => ['nullable', 'uuid'],
            'property_decisions' => ['nullable', 'array'],
            'property_decisions.*' => [Rule::in(['report', 'keep', 'add', 'skip'])],
            'unmapped_decisions' => ['nullable', 'array'],
            'unmapped_decisions.*.action' => ['nullable', Rule::in(['ignore', 'map', 'add', 'request'])],
            'unmapped_decisions.*.key' => ['nullable', 'string', 'max:100'],
            'unmapped_decisions.*.name' => ['nullable', 'string', 'max:255'],
            'unmapped_decisions.*.unit' => ['nullable', 'string', 'max:30'],
            'mismatch_confirmed' => ['nullable', 'boolean'],
            'report' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:51200'],
            'report_no' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function equipmentRules(): array
    {
        return [
            'location_business_entity_id' => ['nullable', 'integer'],
            'variant' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'place' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
