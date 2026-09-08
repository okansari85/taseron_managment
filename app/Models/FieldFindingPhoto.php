<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldFindingPhoto extends Model
{
    protected $fillable = [
        'field_finding_id',
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

    public function fieldFinding(): BelongsTo
    {
        return $this->belongsTo(FieldFinding::class);
    }
}
