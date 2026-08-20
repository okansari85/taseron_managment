<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(
            app(TenantScope::class)
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function operationalRegions(): HasMany
    {
        return $this->hasMany(
            OperationalRegion::class,
            'location_id'
        );
    }

    public function businessEntities(): BelongsToMany
    {
        return $this->belongsToMany(
            BusinessEntity::class,
            'location_business_entities',
            'location_id',
            'business_entity_id'
        )
            ->withPivot([
                'operational_region_id',
                'nace_code',
                'hazard_class',
                'sgk_workplace_number',
            ])
            ->withTimestamps();
    }
}
