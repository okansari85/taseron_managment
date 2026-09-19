<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyEquipmentAnnualControlEquipmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_id',
        'location_emergency_equipment_id',
        'code',
        'criterion',
        'result',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentAnnualControlReport::class, 'report_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(LocationEmergencyEquipment::class, 'location_emergency_equipment_id');
    }
}
