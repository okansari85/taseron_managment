<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\EmergencyEquipmentAnnualControlReport;
use App\Models\LocationBusinessEntity;
use App\Models\LocationEmergencyEquipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Throwable;

class EmergencyEquipmentAnnualControlService
{
    private const FILE_DIRECTORY = 'emergency-equipment-annual-control-reports';

    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function all(LocationBusinessEntity $locationBusinessEntity): Collection
    {
        $this->assertEntityTenant($locationBusinessEntity);

        $reports = EmergencyEquipmentAnnualControlReport::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->withCount('equipment')
            ->with('uploadedByUser:id,name')
            ->orderByDesc('control_date')
            ->get();

        // Section 18/21: "güncel/geçmiş" statik bir kolon değil, control_date'e
        // göre listeleme anında türetilir (Fire Suppression Reports'taki aynı
        // desen).
        return $reports->values()->map(function (EmergencyEquipmentAnnualControlReport $report, int $index) {
            $report->setAttribute('is_current', $index === 0);

            return $report;
        });
    }

    public function find(EmergencyEquipmentAnnualControlReport $report): EmergencyEquipmentAnnualControlReport
    {
        $this->assertOwnership($report);

        return $report->load(['equipment.equipmentType', 'uploadedByUser:id,name']);
    }

    public function create(LocationBusinessEntity $locationBusinessEntity, array $data, UploadedFile $file, ?User $actingUser): EmergencyEquipmentAnnualControlReport
    {
        $this->assertEntityTenant($locationBusinessEntity);

        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $tenantId = $this->tenantContext->id();
        $equipmentInput = $data['equipment'] ?? [];
        $equipmentIds = array_map(fn (array $row) => (int) $row['id'], $equipmentInput);

        $this->assertEquipmentBelongsToBranch($locationBusinessEntity, $equipmentIds);

        $filePath = null;

        try {
            return DB::transaction(function () use ($locationBusinessEntity, $data, $equipmentInput, $tenantId, $file, $actingUser, &$filePath) {
                $filePath = $file->store(self::FILE_DIRECTORY, 'public');

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
                foreach ($equipmentInput as $row) {
                    $syncData[(int) $row['id']] = [
                        'result' => $row['result'] ?? null,
                        'note' => $row['note'] ?? null,
                    ];
                }
                $report->equipment()->sync($syncData);

                foreach (array_keys($syncData) as $equipmentId) {
                    $equipment = LocationEmergencyEquipment::query()->find($equipmentId);
                    if ($equipment) {
                        $this->syncAnnualMaintenanceSnapshot($equipment);
                    }
                }

                return $this->find($report);
            });
        } catch (Throwable $exception) {
            if ($filePath) {
                Storage::disk('public')->delete($filePath);
            }

            throw $exception;
        }
    }

    public function delete(EmergencyEquipmentAnnualControlReport $report): void
    {
        $this->assertOwnership($report);

        // Section 21: rapor silinse de YSC silinmez — sadece rapor + pivot
        // ilişkileri kaldırılır, etkilenen cihazların düz özet alanları
        // (varsa) kalan en güncel rapora göre yeniden hesaplanır.
        $equipmentIds = $report->equipment()->pluck('location_emergency_equipment_id')->all();
        $filePath = $report->file_path;

        $report->delete();

        foreach ($equipmentIds as $equipmentId) {
            $equipment = LocationEmergencyEquipment::query()->find($equipmentId);
            if ($equipment) {
                $this->syncAnnualMaintenanceSnapshot($equipment);
            }
        }

        if ($filePath) {
            Storage::disk('public')->delete($filePath);
        }
    }

    // Mevcut Bakım Bilgileri kartının okuduğu düz alanları (section 5'teki
    // yeni rapor modeliyle çakışan last_annual_maintenance_date /
    // next_annual_maintenance_date / service_company) en güncel yıllık
    // kontrol raporuna göre senkronize eder. Rapor yoksa (silinip başka rapor
    // kalmadıysa) MEVCUT değerlere dokunulmaz — bu alanlar rapor sistemi
    // gelmeden önce elle girilmiş olabilir, zorla temizlenmez.
    private function syncAnnualMaintenanceSnapshot(LocationEmergencyEquipment $equipment): void
    {
        $latestReport = $equipment->annualControlReports()->first();

        if (! $latestReport) {
            return;
        }

        $equipment->update([
            'last_annual_maintenance_date' => $latestReport->control_date,
            'next_annual_maintenance_date' => $latestReport->next_control_date,
            'service_company' => $latestReport->company_name,
        ]);
    }

    private function assertEquipmentBelongsToBranch(LocationBusinessEntity $locationBusinessEntity, array $equipmentIds): void
    {
        if ($equipmentIds === []) {
            return;
        }

        $count = LocationEmergencyEquipment::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->whereIn('id', $equipmentIds)
            ->count();

        if ($count !== count(array_unique($equipmentIds))) {
            throw new RuntimeException('Seçilen ekipmanlardan biri bu şubeye ait değil.');
        }
    }

    private function assertOwnership(EmergencyEquipmentAnnualControlReport $report): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($report->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu rapora erişim yetkiniz yok.');
        }
    }

    private function assertEntityTenant(LocationBusinessEntity $locationBusinessEntity): void
    {
        $tenantId = $locationBusinessEntity->location?->tenant_id
            ?? $locationBusinessEntity->location()->value('tenant_id');

        if ($tenantId !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu şubeye erişim yetkiniz yok.');
        }
    }
}
