<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyEquipmentTypeTipOption extends Model
{
    protected $fillable = [
        'equipment_type_id',
        'label',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentType::class, 'equipment_type_id');
    }
}
