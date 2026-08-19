<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\BusinessEntity;
use App\Models\Brand;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'type',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_organizations'
        )->withTimestamps();
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            BusinessEntity::class,
            'organization_companies',
            'organization_id',
            'business_entity_id'
        )
            ->where('business_entities.type', 'company')
            ->withTimestamps();
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(
            Location::class,
            'organization_locations'
        )->withTimestamps();
    }

    public function brands(): HasMany
    {
        return $this->hasMany(
            Brand::class,
            'organization_id'
        );
    }
}
