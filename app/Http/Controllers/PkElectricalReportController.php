<?php

namespace App\Http\Controllers;

use App\Models\PkInstallation;
use App\Services\Ai\PkTakip\PkElectricalReportReader;
use App\Services\Ai\PkTakip\PkReportFixtureStore;
use App\Services\PkInstallationService;
use App\Services\PkReportAnalysisHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

// pktakip elektrik ailesi tesisatları (Elektrik İç Tesisatı, Topraklama Tesisatı): rapor okuma (tesisat türünün kendi yapay zeka
// talimatı / test verisi), kontrol kaydı ve sistemlerdeki rapordaki ekipmanlar. Rapordaki ekipmanlar (panolar, röleler, ölçüm
// noktaları) Ekipmanlar'a eklenmez. Yangın tesisatının okuma ve kayıt uçları (PkInstallationController) kullanılmaz ve değişmez.
class PkElectricalReportController extends Controller
{
    public function __construct(private PkInstallationService $service)
    {
    }

    // Yapay zeka ile okuma: yalnızca öneri döner, kaydetmez. fixture_id verilirse yapay zeka çağrılmaz (test verisi).
    public function analyze(Request $request, PkInstallation $pkInstallation, PkElectricalReportReader $reader, PkReportFixtureStore $fixtures, PkReportAnalysisHistory $history): JsonResponse
    {
        // Tesisatın kendi türü (talimat ve sistemler bundan) + bütün tesisat türleri (raporun hangi tesisata ait olduğu).
        $types = $this->electricalTypes($pkInstallation);
        $allTypes = $this->service->tesisatTypes();
        $data = $request->validate([
            'report' => ['required_without:fixture_id', 'nullable', 'file', 'mimes:pdf', 'max:51200'],
            'fixture_id' => ['nullable', 'uuid'],
        ]);
        set_time_limit(600);
        $live = empty($data['fixture_id']);
        $locationId = $pkInstallation->location_id;
        try {
            if ($live) {
                $result = $reader->read($request->file('report'), $types, $allTypes);
            } else {
                // Test verisi bu tesisat türünün talimatıyla okunmuş olmalı (türü yazmayan eski elektrik verileri elektrik sayılır).
                $fixture = $fixtures->get($data['fixture_id']);
                $fixtureType = $fixture['context']['type'] ?? PkInstallationService::ELECTRICAL_TYPE_SLUG;
                if (($fixture['context']['mode'] ?? null) !== 'electrical' || $fixtureType !== $types->first()->slug) {
                    throw ValidationException::withMessages(['fixture_id' => 'Bu test verisi ' . $types->first()->name . ' raporu olarak okunmamış.']);
                }
                $result = $reader->fromFixture($fixture, $types, $allTypes);
            }
        } catch (RuntimeException $exception) {
            // Analiz geçmişi: yapay zeka ile yapılan okuma (test verisiyle değil); geçmişe yazılamazsa hata yine döner.
            if ($live) {
                rescue(fn () => $history->recordFailure('installation', $request->file('report'), $exception->getMessage(), $request->user(), $locationId));
            }
            throw ValidationException::withMessages(['report' => 'Rapor okunamadı: ' . $exception->getMessage()]);
        }
        $fileHash = $request->hasFile('report') ? hash_file('sha256', $request->file('report')->getRealPath()) : null;

        $response = $this->service->rememberAnalysis($result, $locationId, $pkInstallation->location_business_entity_id, $fileHash);
        // Rapordaki ekipmanlar Ekipmanlar'daki kayıtlarla eşleştirilmez.
        unset($response['location_equipment']);
        if ($live) {
            rescue(fn () => $history->recordRead($response['analysis_id'], 'installation', $result, $request->file('report'), $fileHash, $request->user(), $locationId, $result['installation_type']['name'] ?? null));
        }

        return response()->json($response);
    }

    // Kontrol kaydı (elle ya da yapay zeka önerisiyle): tesisat raporu ya da sistem raporu; rapordaki ekipmanlar sistemle birlikte.
    public function store(Request $request): JsonResponse
    {
        // Büyük raporlarda PHP'nin alan sınırına (max_input_vars) takılmamak için liste alanları JSON metni olarak gelebilir.
        foreach (['systems', 'findings', 'system_requests'] as $key) {
            if (is_string($value = $request->input($key))) {
                $request->merge([$key => json_decode($value, true) ?? []]);
            }
        }
        set_time_limit(600);
        $data = $request->validate([
            'report' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:51200'],
            'analysis_id' => ['nullable', 'uuid'],
            'installation_id' => ['required', 'integer'],
            'status' => ['required', Rule::in(['uygun', 'uygun_degil'])],
            // Boş = tesisat raporu; system = sistem raporu (tesisatın genel durumunu değiştirmez).
            'report_scope' => ['nullable', Rule::in(['system'])],
            'control_date' => ['required', 'date'],
            'next_control_date' => ['nullable', 'date', 'after_or_equal:control_date'],
            'report_no' => ['nullable', 'string', 'max:100'],
            'inspection_body' => ['nullable', 'string', 'max:255'],
            'conclusion' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            // Tesisat raporu sistemsiz olabilir (genel kontrol listesi); sistem raporunda sistem şartı kayıtta.
            'systems' => ['present', 'array', 'max:50'],
            'systems.*.system_id' => ['required', 'integer', Rule::exists('periodic_installation_systems', 'id')],
            'systems.*.status' => ['nullable', Rule::in(['uygun', 'uygun_degil'])],
            'systems.*.source_names' => ['nullable', 'array'],
            'systems.*.source_names.*' => ['nullable', 'string', 'max:255'],
            'systems.*.findings' => ['nullable', 'array', 'max:500'],
            'systems.*.findings.*' => ['nullable', 'string', 'max:5000'],
            // Rapordaki ekipmanlar (panolar, röleler, ölçüm noktaları): Ekipmanlar'a eklenmez, sistem sonucuyla saklanır.
            'systems.*.components' => ['nullable', 'array', 'max:2000'],
            'systems.*.components.*.code' => ['nullable', 'string', 'max:255'],
            'systems.*.components.*.code_label' => ['nullable', 'string', 'max:255'],
            'systems.*.components.*.place' => ['nullable', 'string', 'max:255'],
            'systems.*.components.*.equipment_name' => ['nullable', 'string', 'max:255'],
            'systems.*.components.*.status' => ['nullable', Rule::in(['uygun', 'uygun_degil', 'belirtilmemis'])],
            'systems.*.components.*.findings' => ['nullable', 'array', 'max:200'],
            'systems.*.components.*.findings.*' => ['nullable', 'string', 'max:5000'],
            'systems.*.components.*.properties' => ['nullable', 'array', 'max:100'],
            'findings' => ['nullable', 'array', 'max:500'],
            'findings.*' => ['nullable', 'string', 'max:5000'],
            'system_requests' => ['nullable', 'array', 'max:50'],
            'system_requests.*.name' => ['required', 'string', 'max:255'],
            'system_requests.*.status' => ['nullable', Rule::in(['uygun', 'uygun_degil'])],
            'system_requests.*.findings' => ['nullable', 'array', 'max:500'],
            'system_requests.*.findings.*' => ['nullable', 'string', 'max:5000'],
        ], [
            'report.required' => 'Periyodik kontrol raporu yüklenmelidir.',
            'status.required' => 'Raporun sonucunu seçin.',
            'control_date.required' => 'Kontrol tarihi zorunludur.',
            'next_control_date.after_or_equal' => 'Gelecek kontrol tarihi kontrol tarihinden önce olamaz.',
            'systems.required' => 'En az bir sistem seçilmelidir.',
            'systems.*.system_id.required' => 'Her sistem için listeden sistem seçin.',
        ]);

        $installation = PkInstallation::query()->findOrFail($data['installation_id']);
        $this->electricalTypes($installation);
        $installation = $this->service->saveElectricalReport($installation, $data, $request->file('report'), $request->user());

        return response()->json($this->service->show($installation), 201);
    }

    // Tesisat sayfası: her sistemin raporları (son kontrol ve önceki raporlar) sonuçları, bulguları ve ölçüm sonuçlarıyla.
    public function systems(PkInstallation $pkInstallation): JsonResponse
    {
        $this->electricalTypes($pkInstallation);

        return response()->json($this->service->electricalSystemReports($pkInstallation));
    }

    // Bu uçlar yalnızca elektrik ailesi tesisatları içindir (elektrik iç tesisatı, topraklama); tesisatın kendi türü döner.
    private function electricalTypes(PkInstallation $installation)
    {
        $types = $this->service->electricalTypes()->where('id', $installation->installation_type_id)->values();
        if ($types->isEmpty()) {
            throw ValidationException::withMessages(['installation_id' => 'Bu işlem bu tesisat türü için kullanılamaz.']);
        }

        return $types;
    }
}
