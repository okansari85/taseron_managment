<?php

namespace App\Services;

use App\Models\EmergencyEquipmentChecklistExclusion;
use App\Models\EmergencyEquipmentType;
use App\Models\EmergencyEquipmentTypeChecklistItem;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class EmergencyEquipmentTypeChecklistItemService
{
    // Alt kategoriler kendi checklist maddesini tutmaz; maddeler her zaman
    // üst kategoriden miras alınır, alt kategori sadece bunlardan bazılarını
    // "kapsam dışı" (exclusion) olarak işaretleyebilir. Bu yüzden hem checklist
    // editörü hem de denetim akışı, hangi tür verilirse verilsin, doğru
    // maddeleri (ve o türe özel kapsam dışı durumunu) tek bu metottan alır.
    public function listForType(EmergencyEquipmentType $equipmentType): Collection
    {
        $source = $equipmentType->parent_id ? $equipmentType->parent()->firstOrFail() : $equipmentType;

        $items = $source->checklistItems()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $excludedIds = $equipmentType->parent_id
            ? EmergencyEquipmentChecklistExclusion::query()
                ->where('equipment_type_id', $equipmentType->id)
                ->whereIn('checklist_item_id', $items->pluck('id'))
                ->pluck('checklist_item_id')
                ->all()
            : [];

        $items->each(fn (EmergencyEquipmentTypeChecklistItem $item) => $item->setAttribute(
            'is_excluded',
            in_array($item->id, $excludedIds, true)
        ));

        return $items;
    }

    public function applicableItemIds(EmergencyEquipmentType $equipmentType): array
    {
        return $this->listForType($equipmentType)
            ->filter(fn (EmergencyEquipmentTypeChecklistItem $item) => $item->is_active && ! $item->is_excluded)
            ->pluck('id')
            ->all();
    }

    public function attach(EmergencyEquipmentType $equipmentType, array $data): EmergencyEquipmentTypeChecklistItem
    {
        if ($equipmentType->parent_id !== null) {
            throw new RuntimeException('Checklist maddeleri yalnızca ana kategori üzerinde tanımlanabilir.');
        }

        return $equipmentType->checklistItems()->create([
            'label' => $data['label'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(EmergencyEquipmentTypeChecklistItem $item, array $data): EmergencyEquipmentTypeChecklistItem
    {
        $item->update([
            'label' => $data['label'] ?? $item->label,
            'sort_order' => array_key_exists('sort_order', $data) ? $data['sort_order'] : $item->sort_order,
            'is_active' => array_key_exists('is_active', $data) ? $data['is_active'] : $item->is_active,
        ]);

        return $item->refresh();
    }

    public function delete(EmergencyEquipmentTypeChecklistItem $item): void
    {
        $item->delete();
    }
}
