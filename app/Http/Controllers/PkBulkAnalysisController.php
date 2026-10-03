<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\PkEquipment;
use App\Models\PkReportAnalysis;
use App\Services\Ai\PkTakip\PkInspectionReportReader;
use App\Services\PkEquipmentPropertyService;
use App\Services\PkEquipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

// Toplu rapor yükleme (raf, transpalet): raporlar tek tek mevcut uçlarla okunur ve kaydedilir ("Rapordan Ekipman Tanımla"
// okuması, rapor penceresinin kaydı). Burada yalnızca iki yardımcı var; ikisi de hiçbir şey kaydetmez.
class PkBulkAnalysisController extends Controller
{
    // Seçilen dosyalar yapay zekaya gönderilmeden önce: daha önce yüklenmiş mi (dosya özeti, sha256)? Yüklenmişse okunmaz,
    // okuma hakkı harcanmaz. Yanıt yalnızca yüklenmiş olanlar: özet => {inspection_id, equipment_id, message}.
    public function duplicates(Request $request, PkEquipmentService $service): JsonResponse
    {
        $data = $request->validate([
            'hashes' => ['required', 'array', 'max:500'],
            'hashes.*' => ['required', 'string', 'size:64', 'alpha_num'],
        ]);

        $found = [];
        foreach (array_unique(array_map('strtolower', $data['hashes'])) as $hash) {
            if ($duplicate = $service->duplicateReport(null, $hash)) {
                $found[$hash] = $duplicate;
            }
        }

        return response()->json((object) $found);
    }

    // Okunmuş ama kaydedilmemiş raporu geri getirir (pencere kapandı, sayfa yenilendi): aynı dosya son 23 saatte "Rapordan
    // Ekipman Tanımla" okumasıyla okunduysa ve okuma önbellekte duruyorsa yapay zekaya yeniden gönderilmez. Yanıt, okuma
    // ucunun yanıtıyla aynı; analysis_id eski okumanınki (kayıtta analiz geçmişi o okumayı "kaydedildi" yapar).
    public function reuse(Request $request, TenantContext $tenant, PkInspectionReportReader $reader, PkEquipmentService $service): JsonResponse
    {
        $data = $request->validate([
            'hashes' => ['required', 'array', 'max:500'],
            'hashes.*' => ['required', 'string', 'size:64', 'alpha_num'],
            'location_id' => ['nullable', 'integer'],
        ]);
        $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;

        $found = [];
        foreach (array_unique(array_map('strtolower', $data['hashes'])) as $hash) {
            // Dosya bu arada kaydedildiyse (aynı dosyanın sonraki okumasıyla) geri getirilmez.
            if ($service->duplicateReport(null, $hash)) {
                continue;
            }
            $read = PkReportAnalysis::query()
                ->where('kind', 'equipment_new')->where('status', 'read')->where('file_hash', $hash)
                ->where('created_at', '>=', now()->subHours(23))
                ->latest('id')->first();
            $cached = $read ? Cache::get('pk-report-analysis:' . $read->uuid) : null;
            if (!is_array($cached) || ($cached['tenant_id'] ?? null) !== $tenant->id() || ($cached['equipment_id'] ?? null) !== null) {
                continue;
            }
            $semantic = (array) ($cached['semantic'] ?? []);
            $result = ['semantic' => $semantic, 'fixture' => null] + $reader->fromFixtureUnknown(['semantic' => $semantic, 'input' => $read->input ?? 'text']);
            // Toplu tüp formu buradan geri getirilmez (kaydı tüp kontrol formu penceresinde).
            if (!empty($result['bulk'])) {
                continue;
            }
            // Yanıt okuma ucuyla aynı yoldan; onun açtığı yeni önbellek kaydı silinir, eski okuma kullanılır.
            $response = $service->rememberNewAnalysis($result, $request->user(), $hash, $locationId);
            Cache::forget('pk-report-analysis:' . $response['analysis_id']);
            $found[$hash] = ['analysis_id' => $read->uuid, 'fixture' => null, 'reused_at' => $read->created_at?->toIso8601String()] + $response;
        }

        return response()->json((object) $found);
    }

    // Satırda seçilen mevcut ekipman için önizleme: rapor bu ekipmanla eşleşiyor mu, teknik özelliklerde fark var mı.
    // Analiz ekipmana bağlanmaz (önbellekteki okuma olduğu gibi kalır), böylece kullanıcı seçimini değiştirebilir.
    public function preview(Request $request, string $analysisId, TenantContext $tenant, PkInspectionReportReader $reader, PkEquipmentPropertyService $properties): JsonResponse
    {
        $data = $request->validate(['equipment_id' => ['required', 'integer']]);
        // Anahtar, PkEquipmentService::analysisKey ile aynı (rapordan ekipman tanımlama okuması, 24 saat).
        $cached = Cache::get('pk-report-analysis:' . $analysisId);
        if (!is_array($cached) || ($cached['tenant_id'] ?? null) !== $tenant->id()) {
            throw ValidationException::withMessages(['analysis_id' => 'Analiz bulunamadı ya da süresi doldu; raporu yeniden okuyun.']);
        }
        $equipment = PkEquipment::query()->with(['type', 'workplace.businessEntity.company'])->findOrFail($data['equipment_id']);
        $semantic = (array) ($cached['semantic'] ?? []);
        $result = $reader->fromSemantic($semantic, $equipment);

        return response()->json([
            'match' => $result['match'] ?? null,
            'properties' => $properties->preview($equipment, $semantic, (array) ($result['equipment'] ?? []), $result['control_date'] ?? null, $request->user()),
        ]);
    }
}
