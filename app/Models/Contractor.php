<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contractor extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_entity_id',
        'contractor_type',
        'short_name',
        'logo_path',
        'status',
    ];

    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(
            BusinessEntity::class,
            'business_entity_id'
        );
    }
}
