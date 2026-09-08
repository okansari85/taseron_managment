<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyEquipmentInspectionPhoto extends Model
{
    protected $fillable = [
        'inspection_id',
        'inspection_item_id',
        'photo_path',
        'order_no',
    ];

    protected $appends = [
        'photo_url',
    ];

    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        return url('/storage/' . ltrim($this->photo_path, '/'));
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentInspection::class, 'inspection_id');
    }
}
