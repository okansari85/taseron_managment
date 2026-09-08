<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\EmergencyEquipmentType;
use App\Models\LocationBusinessEntity;
use App\Models\LocationEmergencyEquipment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Illuminate\Validation\ValidationException;

class LocationEmergencyEquipmentService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function all(LocationBusinessEntity $locationBusinessEntity): Collection
    {
        $this->assertEntityTenant($locationBusinessEntity);

        return LocationEmergencyEquipment::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->with(['equipmentType', 'latestInspection'])
            ->orderByDesc('id')
            ->get();
    }

    public function create(LocationBusinessEntity $locationBusinessEntity, array $data): LocationEmergencyEquipment
    {
        $this->assertEntityTenant($locationBusinessEntity);

        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $this->assertEquipmentTypeSelectable((int) $data['equipment_type_id']);

        return DB::transaction(function () use ($locationBusinessEntity, $data) {
            $equipment = LocationEmergencyEquipment::query()->create([
                'tenant_id' => $this->tenantContext->id(),
                'location_business_entity_id' => $locationBusinessEntity->id,
                'equipment_type_id' => $data['equipment_type_id'],
                'code' => $data['code'] ?? null,
                'location_note' => $data['location_note'] ?? null,
                'install_date' => $data['install_date'] ?? null,
                'status' => $data['status'] ?? 'active',
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $equipment->load(['equipmentType', 'latestInspection']);
        });
    }

    public function update(LocationEmergencyEquipment $equipment, array $data): LocationEmergencyEquipment
    {
        $this->assertOwnership($equipment);

        if (array_key_exists('equipment_type_id', $data)) {
            $this->assertEquipmentTypeSelectable((int) $data['equipment_type_id']);
        }

        $equipment->update($data);

        return $equipment->refresh()->load(['equipmentType', 'latestInspection']);
    }

    public function delete(LocationEmergencyEquipment $equipment): void
    {
        $this->assertOwnership($equipment);

        if ($equipment->inspections()->exists()) {
            throw new RuntimeException('Bu ekipmanın denetim geçmişi var, silinemez. Bunun yerine pasife alabilirsiniz.');
        }

        $equipment->delete();
    }

    private function assertOwnership(LocationEmergencyEquipment $equipment): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($equipment->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu ekipmana erişim yetkiniz yok.');
        }
    }

    private function assertEquipmentTypeSelectable(int $equipmentTypeId): void
    {
        $hasChildren = EmergencyEquipmentType::query()
            ->where('id', $equipmentTypeId)
            ->whereHas('children')
            ->exists();

        if ($hasChildren) {
            throw ValidationException::withMessages([
                'equipment_type_id' => 'Bu ekipman türü bir kategoridir; lütfen bir alt kategori (varyant) seçin.',
            ]);
        }
    }

    private function assertEntityTenant(LocationBusinessEntity $locationBusinessEntity): void
    {
        $tenantId = $locationBusinessEntity->location?->tenant_id
            ?? $locationBusinessEntity->location()->value('tenant_id');

        if ($tenantId !== $this->tenantContext->id()) {
            throw ValidationException::withMessages([
                'location_business_entity' => 'Bu kayıt mevcut tenant kapsamında değil.',
            ]);
        }
    }
}
