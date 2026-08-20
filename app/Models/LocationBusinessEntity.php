<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class LocationBusinessEntity extends Pivot
{
    protected $table = 'location_business_entities';

    public $incrementing = true;

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class);
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
}
