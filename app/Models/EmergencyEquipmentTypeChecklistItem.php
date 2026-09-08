<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyEquipmentTypeChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'equipment_type_id',
        'label',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentType::class, 'equipment_type_id');
    }
}
