<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\EmergencyEquipmentInspection;
use App\Models\LocationEmergencyEquipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Illuminate\Validation\ValidationException;

class EmergencyEquipmentInspectionService
{
    public function __construct(
        private TenantContext $tenantContext,
        private EmergencyEquipmentTypeChecklistItemService $checklistItemService,
    ) {
    }

    public function all(LocationEmergencyEquipment $equipment): Collection
    {
        $this->assertOwnership($equipment);

        return EmergencyEquipmentInspection::query()
            ->where('location_emergency_equipment_id', $equipment->id)
            ->with(['items.checklistItem', 'inspectedByUser'])
            ->orderByDesc('inspected_at')
            ->get();
    }

    public function create(LocationEmergencyEquipment $equipment, array $data, ?User $actingUser): EmergencyEquipmentInspection
    {
        $this->assertOwnership($equipment);

        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $checklistItemIds = array_values(array_unique($data['checklist_item_ids'] ?? []));

        $this->validateChecklistItems($equipment, $checklistItemIds);

        return DB::transaction(function () use ($equipment, $data, $actingUser, $checklistItemIds) {
            $tenantId = $this->tenantContext->id();

            $inspection = EmergencyEquipmentInspection::query()->create([
                'tenant_id' => $tenantId,
                'location_emergency_equipment_id' => $equipment->id,
                'inspected_by_name' => $data['inspected_by_name'] ?? null,
                'inspected_by_user_id' => $data['inspected_by_user_id'] ?? $actingUser?->id,
                'overall_result' => $checklistItemIds === [] ? 'passed' : 'failed',
                'notes' => $data['notes'] ?? null,
                'inspected_at' => $data['inspected_at'] ?? now(),
            ]);

            $now = now();
            $rows = array_map(fn (int $checklistItemId) => [
                'tenant_id' => $tenantId,
                'inspection_id' => $inspection->id,
                'checklist_item_id' => $checklistItemId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $checklistItemIds);

            if ($rows !== []) {
                DB::table('emergency_equipment_inspection_items')->insert($rows);
            }

            return $inspection->load(['items.checklistItem', 'inspectedByUser']);
        });
    }

    // Bir ekipman alt kategoriye bağlıysa, checklist maddeleri fiziksel olarak
    // üst kategoriye ait olduğu için burada doğrudan equipment_type_id eşleşmesi
    // aranmaz; bunun yerine o tür için "uygulanabilir" (aktif + kapsam dışı
    // bırakılmamış) madde id'leri kullanılır. Bu, kapsam dışı bir maddenin
    // denetime hiç dahil edilememesini backend seviyesinde de garanti eder.
    private function validateChecklistItems(LocationEmergencyEquipment $equipment, array $checklistItemIds): void
    {
        if ($checklistItemIds === []) {
            return;
        }

        $applicableIds = $this->checklistItemService->applicableItemIds($equipment->equipmentType);

        if (array_diff($checklistItemIds, $applicableIds) !== []) {
            throw ValidationException::withMessages([
                'checklist_item_ids' => 'Seçilen maddelerden biri bu ekipman türüne ait değil veya kapsam dışı bırakılmış.',
            ]);
        }
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
}
