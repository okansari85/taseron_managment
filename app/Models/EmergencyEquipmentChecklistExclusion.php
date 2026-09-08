<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyEquipmentChecklistExclusion extends Model
{
    protected $fillable = [
        'checklist_item_id',
        'equipment_type_id',
    ];

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentTypeChecklistItem::class, 'checklist_item_id');
    }

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentType::class, 'equipment_type_id');
    }
}
