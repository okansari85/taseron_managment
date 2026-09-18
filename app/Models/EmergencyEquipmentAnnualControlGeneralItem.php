<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyEquipmentAnnualControlGeneralItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_id',
        'code',
        'criterion',
        'result',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentAnnualControlReport::class, 'report_id');
    }
}
