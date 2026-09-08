<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\EmergencyEquipmentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class EmergencyEquipmentTypeService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function all(): Collection
    {
        return EmergencyEquipmentType::query()
            ->with('parent:id,name')
            ->withCount(['locationEquipment', 'checklistItems', 'children'])
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): EmergencyEquipmentType
    {
        return EmergencyEquipmentType::query()
            ->with('parent:id,name')
            ->withCount(['locationEquipment', 'checklistItems', 'children'])
            ->findOrFail($id);
    }

    public function create(array $data): EmergencyEquipmentType
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $this->assertParentDepth($data['parent_id'] ?? null);

        return DB::transaction(function () use ($data) {
            $data['tenant_id'] = $this->tenantContext->id();

            return EmergencyEquipmentType::query()->create($data);
        });
    }

    public function update(EmergencyEquipmentType $equipmentType, array $data): EmergencyEquipmentType
    {
        $this->assertOwnership($equipmentType);

        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'];

            if ($parentId !== null && (int) $parentId === $equipmentType->id) {
                throw new RuntimeException('Bir ekipman türü kendi kendisinin üst kategorisi olamaz.');
            }

            if ($parentId !== null && $equipmentType->children()->exists()) {
                throw new RuntimeException('Alt kategorileri olan bir ekipman türü başka bir kategorinin altına taşınamaz.');
            }

            $this->assertParentDepth($parentId);
        }

        $equipmentType->update($data);

        return $equipmentType->refresh();
    }

    public function delete(EmergencyEquipmentType $equipmentType): void
    {
        $this->assertOwnership($equipmentType);

        if ($equipmentType->children()->exists()) {
            throw new RuntimeException('Alt kategorileri bulunan bir ekipman türünü silemezsiniz.');
        }

        if ($equipmentType->locationEquipment()->exists()) {
            throw new RuntimeException('Bu ekipman türünü kullanan şube kayıtları var, silinemez.');
        }

        $equipmentType->delete();
    }

    private function assertParentDepth(?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = EmergencyEquipmentType::query()->find($parentId);

        if ($parent === null) {
            throw new RuntimeException('Seçilen üst kategori bulunamadı.');
        }

        if ($parent->parent_id !== null) {
            throw new RuntimeException('En fazla 2 seviyeli kategori/alt kategori yapısı desteklenir.');
        }
    }

    private function assertOwnership(EmergencyEquipmentType $equipmentType): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($equipmentType->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu ekipman türüne erişim yetkiniz yok.');
        }
    }
}
