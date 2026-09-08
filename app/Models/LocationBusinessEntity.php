<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationBusinessEntity extends Pivot
{
    protected $table = 'location_business_entities';

    public $incrementing = true;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class);
    }

    public function operationalRegion(): BelongsTo
    {
        return $this->belongsTo(OperationalRegion::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function emergencyEquipment(): HasMany
    {
        return $this->hasMany(LocationEmergencyEquipment::class, 'location_business_entity_id');
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(
            Brand::class,
            'location_business_entity_brands',
            'location_business_entity_id',
            'brand_id'
        )->withTimestamps();
    }

    public function photos(): HasMany
    {
        return $this->hasMany(LocationBusinessEntityPhoto::class, 'location_business_entity_id')->orderBy('order_no');
    }
}
