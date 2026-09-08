<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\EmergencyEquipmentInspection;
use App\Models\LocationEmergencyEquipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            ->with(['items.checklistItem', 'items.photos', 'inspectedByUser', 'photos'])
            ->orderByDesc('inspected_at')
            ->get();
    }

    public function create(LocationEmergencyEquipment $equipment, array $data, ?User $actingUser): EmergencyEquipmentInspection
    {
        $this->assertOwnership($equipment);

        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $items = $data['items'] ?? [];
        $checklistItemIds = array_values(array_unique(array_map(
            fn (array $item) => (int) $item['checklist_item_id'],
            $items
        )));
        $photos = $data['photos'] ?? [];

        $this->validateChecklistItems($equipment, $checklistItemIds);

        $storedPhotoPaths = [];

        try {
            return DB::transaction(function () use ($equipment, $data, $actingUser, $checklistItemIds, $items, $photos, &$storedPhotoPaths) {
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

                foreach ($items as $item) {
                    $inspectionItem = $inspection->items()->create([
                        'tenant_id' => $tenantId,
                        'checklist_item_id' => $item['checklist_item_id'],
                        'note' => $item['note'] ?? null,
                    ]);

                    if (($item['photo'] ?? null) instanceof UploadedFile) {
                        $storedPhotoPaths = [...$storedPhotoPaths, ...$this->addPhotos($inspection, [$item['photo']], $inspectionItem->id)];
                    }
                }

                $storedPhotoPaths = [...$storedPhotoPaths, ...$this->addPhotos($inspection, $photos)];

                return $inspection->load(['items.checklistItem', 'items.photos', 'inspectedByUser', 'photos']);
            });
        } catch (\Throwable $exception) {
            foreach ($storedPhotoPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
    }

    public function update(EmergencyEquipmentInspection $inspection, array $data): EmergencyEquipmentInspection
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($inspection->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu denetime erişim yetkiniz yok.');
        }

        $equipment = $inspection->locationEquipment;
        $items = $data['items'] ?? [];
        $checklistItemIds = array_values(array_unique(array_map(
            fn (array $item) => (int) $item['checklist_item_id'],
            $items
        )));
        $generalPhotos = $data['photos'] ?? [];
        $removePhotoIds = $data['remove_photo_ids'] ?? [];

        $this->validateChecklistItems($equipment, $checklistItemIds);

        $tenantId = $this->tenantContext->id();
        $storedPhotoPaths = [];
        $pathsToDeleteOnSuccess = [];

        try {
            $inspection = DB::transaction(function () use ($inspection, $data, $items, $checklistItemIds, $generalPhotos, $removePhotoIds, $tenantId, &$storedPhotoPaths, &$pathsToDeleteOnSuccess) {
                $existingItems = $inspection->items()->with('photos')->get()->keyBy('id');
                $keepIds = [];

                foreach ($items as $item) {
                    $existingId = $item['id'] ?? null;

                    if ($existingId && $existingItems->has($existingId)) {
                        $inspectionItem = $existingItems[$existingId];
                        $inspectionItem->update([
                            'checklist_item_id' => $item['checklist_item_id'],
                            'note' => $item['note'] ?? null,
                        ]);
                    } else {
                        $inspectionItem = $inspection->items()->create([
                            'tenant_id' => $tenantId,
                            'checklist_item_id' => $item['checklist_item_id'],
                            'note' => $item['note'] ?? null,
                        ]);
                    }

                    $keepIds[] = $inspectionItem->id;

                    $replacingPhoto = ($item['photo'] ?? null) instanceof UploadedFile;
                    if ($replacingPhoto || ! empty($item['remove_photo'])) {
                        foreach ($inspectionItem->photos as $photo) {
                            $pathsToDeleteOnSuccess[] = $photo->photo_path;
                            $photo->delete();
                        }
                    }
                    if ($replacingPhoto) {
                        $storedPhotoPaths = [...$storedPhotoPaths, ...$this->addPhotos($inspection, [$item['photo']], $inspectionItem->id)];
                    }
                }

                foreach ($existingItems as $id => $existingItem) {
                    if (! in_array($id, $keepIds, true)) {
                        foreach ($existingItem->photos as $photo) {
                            $pathsToDeleteOnSuccess[] = $photo->photo_path;
                            $photo->delete();
                        }
                        $existingItem->delete();
                    }
                }

                if ($removePhotoIds !== []) {
                    $toRemove = $inspection->photos()->whereIn('id', $removePhotoIds)->whereNull('inspection_item_id')->get();
                    foreach ($toRemove as $photo) {
                        $pathsToDeleteOnSuccess[] = $photo->photo_path;
                        $photo->delete();
                    }
                }

                $storedPhotoPaths = [...$storedPhotoPaths, ...$this->addPhotos($inspection, $generalPhotos)];

                $inspection->update([
                    'overall_result' => $checklistItemIds === [] ? 'passed' : 'failed',
                    'notes' => $data['notes'] ?? null,
                    'inspected_at' => $data['inspected_at'] ?? $inspection->inspected_at,
                ]);

                return $inspection->fresh(['items.checklistItem', 'items.photos', 'inspectedByUser', 'photos']);
            });
        } catch (\Throwable $exception) {
            foreach ($storedPhotoPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }

        foreach ($pathsToDeleteOnSuccess as $path) {
            Storage::disk('public')->delete($path);
        }

        return $inspection;
    }

    private function addPhotos(EmergencyEquipmentInspection $inspection, array $photos, ?int $inspectionItemId = null): array
    {
        $photos = array_values(array_filter($photos, fn ($photo) => $photo instanceof UploadedFile));

        if ($photos === []) {
            return [];
        }

        $nextOrder = ((int) DB::table('emergency_equipment_inspection_photos')
            ->where('inspection_id', $inspection->id)
            ->max('order_no')) + 1;

        $storedPaths = [];
        $rows = [];
        $now = now();

        foreach ($photos as $photo) {
            $path = $photo->store('inspection-photos', 'public');
            $storedPaths[] = $path;
            $rows[] = [
                'inspection_id' => $inspection->id,
                'inspection_item_id' => $inspectionItemId,
                'photo_path' => $path,
                'order_no' => $nextOrder++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('emergency_equipment_inspection_photos')->insert($rows);

        return $storedPaths;
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
