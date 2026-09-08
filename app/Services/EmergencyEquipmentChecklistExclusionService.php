<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\EmergencyEquipmentChecklistExclusion;
use App\Models\EmergencyEquipmentType;
use App\Models\EmergencyEquipmentTypeChecklistItem;
use LogicException;
use RuntimeException;

class EmergencyEquipmentChecklistExclusionService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function exclude(EmergencyEquipmentType $subCategory, EmergencyEquipmentTypeChecklistItem $item): void
    {
        $this->assertExcludable($subCategory, $item);

        EmergencyEquipmentChecklistExclusion::query()->firstOrCreate([
            'checklist_item_id' => $item->id,
            'equipment_type_id' => $subCategory->id,
        ]);
    }

    public function include(EmergencyEquipmentType $subCategory, EmergencyEquipmentTypeChecklistItem $item): void
    {
        $this->assertExcludable($subCategory, $item);

        EmergencyEquipmentChecklistExclusion::query()
            ->where('checklist_item_id', $item->id)
            ->where('equipment_type_id', $subCategory->id)
            ->delete();
    }

    private function assertExcludable(EmergencyEquipmentType $subCategory, EmergencyEquipmentTypeChecklistItem $item): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($subCategory->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu ekipman türüne erişim yetkiniz yok.');
        }

        if ($subCategory->parent_id === null) {
            throw new RuntimeException('Kapsam dışı bırakma yalnızca alt kategoriler için tanımlanabilir.');
        }

        if ($item->equipment_type_id !== $subCategory->parent_id) {
            throw new RuntimeException('Bu checklist maddesi bu kategoriye ait değil.');
        }
    }
}
