<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Location extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'name', 'image', 'address', 'city_id', 'district_id', 'latitude', 'longitude', 'is_active', 'default_brand_id', 'allows_multiple_branches'];
    protected $casts = ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'is_active' => 'boolean', 'allows_multiple_branches' => 'boolean'];
    protected $appends = ['organization'];

    protected static function booted(): void { static::addGlobalScope(app(TenantScope::class)); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function city(): BelongsTo { return $this->belongsTo(City::class); }
    public function district(): BelongsTo { return $this->belongsTo(District::class); }
    public function defaultBrand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function organizations(): BelongsToMany { return $this->belongsToMany(Organization::class, 'organization_locations', 'location_id', 'organization_id')->withTimestamps(); }
    public function getOrganizationAttribute(): ?Organization { return $this->organizations->first(); }
    public function operationalRegions(): HasMany { return $this->hasMany(OperationalRegion::class, 'location_id'); }
    public function businessEntities(): BelongsToMany
    {
        return $this->belongsToMany(BusinessEntity::class, 'location_business_entities', 'location_id', 'business_entity_id')->using(LocationBusinessEntity::class)->withPivot(['id', 'code', 'floor', 'operational_region_id', 'activity', 'sub_activity', 'nace_code', 'hazard_class', 'sgk_workplace_number', 'address', 'is_active'])->withTimestamps();
    }
    public function experts(): HasManyThrough
    {
        // location_experts artık location_id yerine location_business_entity_id ile ilişkili
        // (bkz. 2026_09_05_000001_change_location_id_to_location_business_entity_id_on_location_experts_table).
        return $this->hasManyThrough(
            LocationExpert::class,
            LocationBusinessEntity::class,
            'location_id',
            'location_business_entity_id',
            'id',
            'id'
        );
    }
}
