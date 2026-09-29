<?php

namespace App\Http\Controllers;

use App\Models\PkInstallation;
use App\Models\PkInstallationReport;
use App\Services\Ai\PkTakip\PkInstallationReportReader;
use App\Services\Ai\PkTakip\PkReportFixtureStore;
use App\Services\PkInstallationService;
use App\Services\PkReportAnalysisHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// pktakip tesisatları (Yangın Tesisatı, Yangın Algılama ve Uyarı Sistemleri): liste, detay, tesisat ekleme / düzenleme,
// rapor okuma (yapay zeka / test verisi), kontrol (rapor) kaydı.
class PkInstallationController extends Controller
{
    public function __construct(private PkInstallationService $service)
    {
    }

    public function catalog(): JsonResponse
    {
        return response()->json($this->service->catalog());
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($request->validate([
            'location_id' => ['nullable', 'integer'],
            'workplace_id' => ['nullable', 'integer'],
        ])));
    }

    public function show(PkInstallation $pkInstallation): JsonResponse
    {
        return response()->json($this->service->show($pkInstallation));
    }

    // Raporsuz tesisat ekleme: tür, yer, ad, katalogdan sistemler.
    public function store(Request $request): JsonResponse
    {
        $installation = $this->service->create($request->validate([
            'installation_type_id' => ['required', 'integer'],
            'location_id' => ['required', 'integer'],
            'location_business_entity_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'system_ids' => ['nullable', 'array', 'max:50'],
            'system_ids.*' => ['integer'],
        ], [
            'installation_type_id.required' => 'Tesisat türünü seçin.',
            'location_id.required' => 'Lokasyon seçin.',
        ]), $request->user());

        return response()->json($this->service->show($installation), 201);
    }

    public function update(Request $request, PkInstallation $pkInstallation): JsonResponse
    {
        return response()->json($this->service->update($pkInstallation, $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'system_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'system_ids.*' => ['integer'],
        ])));
    }

    public function destroy(PkInstallation $pkInstallation): JsonResponse
    {
        $this->service->delete($pkInstallation);

        return response()->json(['deleted' => true]);
    }

    // Yapay zeka ile okuma: yalnızca öneri döner, kaydetmez. fixture_id verilirse yapay zeka çağrılmaz.
    public function analyzeReport(Request $request, PkInstallationReportReader $reader, PkReportFixtureStore $fixtures, PkReportAnalysisHistory $history): JsonResponse
    {
        $data = $request->validate([
            'report' => ['required_without:fixture_id', 'nullable', 'file', 'mimes:pdf', 'max:51200'],
            'fixture_id' => ['nullable', 'uuid'],
            'location_id' => ['required', 'integer'],
            'workplace_id' => ['nullable', 'integer'],
        ]);
        set_time_limit(600);
        // Analiz geçmişi: yapay zeka ile yapılan okuma (test verisiyle değil); geçmişe yazılamazsa okuma yine döner.
        $live = empty($data['fixture_id']);

        // Tesisat türünü (yangın / algılama) ve sistem adlarını yapay zeka katalogdan seçer.
        $types = $this->service->types();
        try {
            $result = !$live
                ? $reader->fromFixture($fixtures->get($data['fixture_id']), $types)
                : $reader->read($request->file('report'), $types);
        } catch (RuntimeException $exception) {
            if ($live) {
                rescue(fn () => $history->recordFailure('installation', $request->file('report'), $exception->getMessage(), $request->user(), (int) $data['location_id']));
            }
            throw ValidationException::withMessages(['report' => 'Rapor okunamadı: ' . $exception->getMessage()]);
        }
        $fileHash = $request->hasFile('report') ? hash_file('sha256', $request->file('report')->getRealPath()) : null;

        $response = $this->service->rememberAnalysis($result, (int) $data['location_id'], isset($data['workplace_id']) ? (int) $data['workplace_id'] : null, $fileHash);
        if ($live) {
            rescue(fn () => $history->recordRead($response['analysis_id'], 'installation', $result, $request->file('report'), $fileHash, $request->user(), (int) $data['location_id'], $result['installation_type']['name'] ?? null));
        }

        return response()->json($response);
    }

    // Rapor kaydı: mevcut tesisata ya da yeni tesisatla birlikte (yapay zeka önerisiyle ya da elle).
    public function storeReport(Request $request): JsonResponse
    {
        // Büyük raporlarda (yüzlerce ekipman) PHP'nin alan sınırına (max_input_vars) takılmamak için liste alanları JSON
        // metni olarak gelebilir.
        foreach (['systems', 'findings', 'system_requests', 'equipment'] as $key) {
            if (is_string($value = $request->input($key))) {
                $request->merge([$key => json_decode($value, true) ?? []]);
            }
        }
        set_time_limit(600);
        $data = $request->validate([
            'report' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:51200'],
            'analysis_id' => ['nullable', 'uuid'],
            'installation_id' => ['nullable', 'integer'],
            'installation_type_id' => ['required_without:installation_id', 'nullable', 'integer'],
            'location_id' => ['required_without:installation_id', 'nullable', 'integer'],
            'location_business_entity_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['uygun', 'uygun_degil'])],
            'control_date' => ['required', 'date'],
            'next_control_date' => ['nullable', 'date', 'after_or_equal:control_date'],
            'report_no' => ['nullable', 'string', 'max:100'],
            'inspection_body' => ['nullable', 'string', 'max:255'],
            'conclusion' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'systems' => ['required', 'array', 'min:1', 'max:50'],
            // Sistemler ön tanımlı (tesisat kataloğu): her sistem katalogdan seçilir.
            'systems.*.system_id' => ['required', 'integer', Rule::exists('periodic_installation_systems', 'id')],
            'systems.*.status' => ['nullable', Rule::in(['uygun', 'uygun_degil'])],
            'systems.*.source_names' => ['nullable', 'array'],
            'systems.*.source_names.*' => ['nullable', 'string', 'max:255'],
            'systems.*.findings' => ['nullable', 'array', 'max:500'],
            'systems.*.findings.*' => ['nullable', 'string', 'max:5000'],
            // Hiçbir sisteme bağlı olmayan (genel) bulgular.
            'findings' => ['nullable', 'array', 'max:500'],
            'findings.*' => ['nullable', 'string', 'max:5000'],
            // Raporda geçip katalogda olmayan sistemler: kataloğa eklenmesi için talep (rapordaki durum ve bulgularıyla).
            'system_requests' => ['nullable', 'array', 'max:50'],
            'system_requests.*.name' => ['required', 'string', 'max:255'],
            'system_requests.*.status' => ['nullable', Rule::in(['uygun', 'uygun_degil'])],
            'system_requests.*.findings' => ['nullable', 'array', 'max:500'],
            'system_requests.*.findings.*' => ['nullable', 'string', 'max:5000'],
            // Rapordaki ekipmanlar: mevcut ekipman (equipment_id) ya da yeni ekipman; her birine bu raporla kontrol kaydı.
            'equipment' => ['nullable', 'array', 'max:500'],
            'equipment.*.system_id' => ['required', 'integer'],
            'equipment.*.equipment_type_id' => ['required', 'integer'],
            'equipment.*.equipment_id' => ['nullable', 'integer'],
            // Boşsa belirtilmemiş: ekipman raporda var ama uygunluğu yazmıyor.
            'equipment.*.status' => ['nullable', Rule::in(['uygun', 'uygun_degil', 'belirtilmemis'])],
            'equipment.*.code' => ['nullable', 'string', 'max:100'],
            'equipment.*.place' => ['nullable', 'string', 'max:255'],
            'equipment.*.brand' => ['nullable', 'string', 'max:100'],
            'equipment.*.model' => ['nullable', 'string', 'max:100'],
            'equipment.*.serial_no' => ['nullable', 'string', 'max:100'],
            'equipment.*.variant' => ['nullable', 'string', 'max:50'],
            'equipment.*.properties' => ['nullable', 'array', 'max:100'],
            'equipment.*.findings' => ['nullable', 'array', 'max:200'],
            'equipment.*.findings.*' => ['nullable', 'string', 'max:5000'],
        ], [
            'report.required' => 'Periyodik kontrol raporu yüklenmelidir.',
            'installation_type_id.required_without' => 'Tesisat türünü seçin.',
            'control_date.required' => 'Kontrol tarihi zorunludur.',
            'next_control_date.after_or_equal' => 'Gelecek kontrol tarihi kontrol tarihinden önce olamaz.',
            'systems.required' => 'En az bir sistem seçilmelidir.',
            'systems.*.system_id.required' => 'Her sistem için listeden sistem seçin.',
        ]);

        $installation = $this->service->saveReport($data, $request->file('report'), $request->user());

        return response()->json($this->service->show($installation), 201);
    }

    // Rapor bekleniyor: firma kontrolü yaptı, rapor henüz gelmedi (dosyasız). Tesisata bağlı ekipmanlara ayrı kayıt açılmaz.
    public function storePending(Request $request, PkInstallation $pkInstallation): JsonResponse
    {
        $this->service->markPending($pkInstallation, $request->validate([
            'control_date' => ['required', 'date'],
            'inspection_body' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ], [
            'control_date.required' => 'Kontrol tarihi zorunludur.',
        ]), $request->user());

        return response()->json($this->service->show($pkInstallation), 201);
    }

    public function downloadReport(PkInstallation $pkInstallation, PkInstallationReport $pkInstallationReport): BinaryFileResponse
    {
        [$path, $name] = $this->service->reportFile($pkInstallation, $pkInstallationReport);

        return response()->file($path, ['Content-Disposition' => 'inline; filename="' . addslashes($name) . '"']);
    }

    public function destroyReport(PkInstallation $pkInstallation, PkInstallationReport $pkInstallationReport): JsonResponse
    {
        $this->service->deleteReport($pkInstallation, $pkInstallationReport);

        return response()->json($this->service->show($pkInstallation));
    }
}
