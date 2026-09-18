<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\EmergencyEquipmentAnnualControlReport;
use App\Models\EmergencyEquipmentType;
use App\Models\LocationBusinessEntity;
use App\Models\LocationEmergencyEquipment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

// Table_shape/Camelot analiz çıktısını (FireSuppressionUnifiedNormalizer'ın
// ürettiği AYNI genel şekil - report/systems/equipment/control_items) YSC'nin
// KENDİ alan modeline (LocationEmergencyEquipment + EmergencyEquipmentAnnual
// ControlReport) yazar. Extraction tarafı rapor tipinden bağımsız/ortak
// kalır (bkz. TemplateDrivenFireSuppressionExtractor) - sadece bu servis
// "hangi hedef sisteme kaydedileceği" dallanmasını taşır.
//
// control_items içindeki scope=equipment maddeleri, üretildikleri sırayla
// (extractor'daki equipment-senkron döngüsüyle) $tables['equipment'] ile
// AYNI SIRADADIR - equipment kodu (bu raporlarda genelde "Cihazın Bulunduğu
// yer" gibi TEKİL OLMAYAN bir kimlik) üzerinden değil, bu SIRA üzerinden
// eşleştirilir.
//
// Ekipman "aynı fiziksel tüp mü" sorusu YscMatchingProfile'ın genel
// (kod-tekli) findExact()'ına DEVREDİLMEZ - Tüp No tek başına güvenilir
// değil (aynı numara farklı fiziksel tüplerde tekrarlanabiliyor, bkz. gerçek
// AKTAŞ raporunda "7" hem Eğitim Salonu'nda hem Yedek'te). Kesin eşleşme
// için Tüp No + konum + tip'in ÜÇÜNÜN BİRDEN aynı olması şart - bu üçü
// aynıysa aynı fiziksel ekipman, değilse (biri bile farklıysa) yeni kayıt.
class YscAnnualControlSaveService
{
    public function __construct(
        private TenantContext $tenantContext,
        private LocationEmergencyEquipmentService $equipmentService,
        private EmergencyEquipmentAnnualControlService $reportService,
    ) {
    }

    // $data: onay ekranından gelen (kullanıcı tarafından düzenlenmiş
    // olabilecek) taslak - aynı FireSuppressionReport akışındaki gibi
    // (bkz. StoreFireSuppressionReportRequest) equipment/control_items
    // DÜZ (flat) diziler olarak gelir, systems[] iç içe yapısı değil.
    public function save(
        LocationBusinessEntity $locationBusinessEntity,
        array $data,
        UploadedFile $file,
        ?User $actingUser
    ): EmergencyEquipmentAnnualControlReport {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $tenantId = $this->tenantContext->id();
        $equipmentItems = array_values(array_filter((array) ($data['equipment'] ?? []), 'is_array'));
        $allControlItems = array_values(array_filter((array) ($data['control_items'] ?? []), 'is_array'));

        $equipmentControlItems = array_values(array_filter(
            $allControlItems,
            fn (array $c) => ($c['scope'] ?? null) === 'equipment'
        ));
        $generalControlItems = array_values(array_filter(
            $allControlItems,
            fn (array $c) => ($c['scope'] ?? null) === 'system'
        ));

        $filePath = null;

        try {
            return DB::transaction(function () use (
                $locationBusinessEntity,
                $data,
                $equipmentItems,
                $equipmentControlItems,
                $generalControlItems,
                $tenantId,
                $file,
                $actingUser,
                &$filePath
            ) {
                $equipmentTypeCache = [];
                $equipmentIds = [];
                $claimedEquipmentIds = [];

                foreach ($equipmentItems as $index => $item) {
                    $properties = (array) ($item['properties'] ?? []);
                    $code = $this->stringOrNull($properties['Tüp No'] ?? null);
                    $locationNote = $this->stringOrNull($item['code'] ?? null);
                    [$tip, $capacityKg] = $this->parseEquipmentTypeText(
                        (string) ($properties['Cihaz Tipi'] ?? '')
                    );

                    $existing = $this->findExistingEquipment(
                        $locationBusinessEntity,
                        $code,
                        $locationNote,
                        $tip,
                        $capacityKg,
                        $claimedEquipmentIds
                    );

                    if ($existing !== null) {
                        $equipmentIds[$index] = $existing;
                        $claimedEquipmentIds[] = $existing;
                        continue;
                    }

                    $typeCacheKey = $tenantId . '|' . ($tip ?? '') . '|' . ($capacityKg ?? '');
                    if (! isset($equipmentTypeCache[$typeCacheKey])) {
                        $equipmentTypeCache[$typeCacheKey] = $this->resolveEquipmentType($tenantId, $tip, $capacityKg);
                    }

                    $equipment = $this->equipmentService->create($locationBusinessEntity, [
                        'equipment_type_id' => $equipmentTypeCache[$typeCacheKey],
                        'code' => $code,
                        'location_note' => $locationNote,
                    ]);
                    $equipmentIds[$index] = $equipment->id;
                    $claimedEquipmentIds[] = $equipment->id;
                }

                $filePath = $file->store('emergency-equipment-annual-control-reports', 'public');

                $report = EmergencyEquipmentAnnualControlReport::query()->create([
                    'tenant_id' => $tenantId,
                    'location_business_entity_id' => $locationBusinessEntity->id,
                    'control_date' => $data['control_date'],
                    'next_control_date' => $data['next_control_date'] ?? null,
                    'result' => $data['result'] ?? null,
                    'company_name' => $data['company_name'] ?? null,
                    'file_path' => $filePath,
                    'file_name' => $file->getClientOriginalName(),
                    'uploaded_by_user_id' => $actingUser?->id,
                    'notes' => $data['notes'] ?? null,
                ]);

                $syncData = [];
                foreach ($equipmentControlItems as $index => $controlItem) {
                    if (! isset($equipmentIds[$index])) continue;
                    $syncData[$equipmentIds[$index]] = [
                        'result' => $this->stringOrNull($controlItem['result_normalized'] ?? $controlItem['result'] ?? null),
                        'note' => $this->stringOrNull($controlItem['criterion'] ?? null),
                    ];
                }
                $report->equipment()->sync($syncData);

                foreach ($generalControlItems as $item) {
                    $report->generalItems()->create([
                        'code' => (string) ($item['code'] ?? ''),
                        'criterion' => (string) ($item['criterion'] ?? ''),
                        'result' => $this->stringOrNull($item['result_normalized'] ?? $item['result'] ?? null),
                    ]);
                }

                return $this->reportService->find($report);
            });
        } catch (Throwable $exception) {
            if ($filePath) {
                Storage::disk('public')->delete($filePath);
            }

            throw $exception;
        }
    }

    // Kesin eşleşme: Tüp No + konum + tip(+kapasite) ÜÇÜNÜN/DÖRDÜNÜN BİRDEN
    // aynı olması şart - kod tek başına yeterli değil (aynı Tüp No farklı
    // fiziksel tüplerde tekrarlanabiliyor). $claimedEquipmentIds, AYNI
    // yükleme partisi içinde daha önce bir satıra atanmış id'leri taşır -
    // bu partide zaten kullanılmış bir id'yi ikinci kez "aynı ekipman"
    // sayıp pivot'ta üzerine yazmamak için.
    private function findExistingEquipment(
        LocationBusinessEntity $locationBusinessEntity,
        ?string $code,
        ?string $locationNote,
        ?string $tip,
        ?float $capacityKg,
        array $claimedEquipmentIds
    ): ?int {
        if ($code === null || $locationNote === null) {
            return null;
        }

        $equipment = LocationEmergencyEquipment::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->where('code', $code)
            ->where('location_note', $locationNote)
            ->whereNotIn('id', $claimedEquipmentIds)
            ->whereHas('equipmentType', function ($query) use ($tip, $capacityKg) {
                $query->where('tip', $tip)->where('capacity_kg', $capacityKg);
            })
            ->first();

        return $equipment?->id;
    }

    // "5KG CO2" / "6KG KKT" / "50KG KKT" -> [tip, capacity_kg]. Regex YOK -
    // baştaki rakamları ctype_digit ile say, ardından "kg" ayracını
    // stripos ile bul, kalanı tip olarak al. Format uymuyorsa (baştan rakam
    // yoksa) tüm metin tip olarak kalır, kapasite null.
    private function parseEquipmentTypeText(string $value): array
    {
        $value = trim($value);
        if ($value === '') return [null, null];

        $digitsEnd = 0;
        while ($digitsEnd < strlen($value) && ctype_digit($value[$digitsEnd])) {
            $digitsEnd++;
        }

        if ($digitsEnd === 0) {
            return [$value, null];
        }

        $capacityKg = (float) substr($value, 0, $digitsEnd);
        $rest = ltrim(substr($value, $digitsEnd));

        if (stripos($rest, 'kg') === 0) {
            $rest = ltrim(substr($rest, 2));
        }

        $tip = trim($rest);

        return [$tip !== '' ? $tip : null, $capacityKg];
    }

    private function resolveEquipmentType(int $tenantId, ?string $tip, ?float $capacityKg): int
    {
        $name = trim(($tip ?? 'Yangın Söndürücü') . ($capacityKg !== null ? ' ' . rtrim(rtrim((string) $capacityKg, '0'), '.') . 'KG' : ''));

        $type = EmergencyEquipmentType::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'tip' => $tip,
                'capacity_kg' => $capacityKg,
            ],
            [
                'name' => $name !== '' ? $name : 'Yangın Söndürücü',
                'is_active' => true,
            ]
        );

        return $type->id;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
