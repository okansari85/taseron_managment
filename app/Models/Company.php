<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'short_name',
        'description',
        'is_active',
        'company_type',
        'business_entity_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(
            BusinessEntity::class,
            'business_entity_id'
        );
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(
            Organization::class,
            'organization_companies',
            'company_id',
            'organization_id',
            'id',
            'id'
        )->withPivot('company_node_id')->withTimestamps();
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(
            Brand::class,
            'company_brands',
            'company_id',
            'brand_id'
        )->withPivot('brand_node_id')->withTimestamps();
    }
}
