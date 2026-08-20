<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BusinessEntity extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'type',
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

    public function company(): HasOne
    {
        return $this->hasOne(Company::class);
    }

    public function contractor(): HasOne
    {
        return $this->hasOne(Contractor::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(
            Location::class,
            'location_business_entities',
            'business_entity_id',
            'location_id'
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
