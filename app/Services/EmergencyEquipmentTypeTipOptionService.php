<?php

namespace App\Services;

use App\Models\EmergencyEquipmentType;
use App\Models\EmergencyEquipmentTypeTipOption;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class EmergencyEquipmentTypeTipOptionService
{
    public function listForType(EmergencyEquipmentType $equipmentType): Collection
    {
        return $equipmentType->tipOptions()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function attach(EmergencyEquipmentType $equipmentType, array $data): EmergencyEquipmentTypeTipOption
    {
        if ($equipmentType->parent_id !== null) {
            throw new RuntimeException('Tip seçenekleri yalnızca ana kategori üzerinde tanımlanabilir.');
        }

        return $equipmentType->tipOptions()->create([
            'label' => $data['label'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function update(EmergencyEquipmentTypeTipOption $tipOption, array $data): EmergencyEquipmentTypeTipOption
    {
        $tipOption->update([
            'label' => $data['label'] ?? $tipOption->label,
            'sort_order' => array_key_exists('sort_order', $data) ? $data['sort_order'] : $tipOption->sort_order,
        ]);

        return $tipOption->refresh();
    }

    public function delete(EmergencyEquipmentTypeTipOption $tipOption): void
    {
        $tipOption->delete();
    }
}
