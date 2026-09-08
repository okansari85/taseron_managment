<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'logo_path',
        'featured_brand_id',
        'operational_area_enabled',
        'location_view_mode',
    ];

    protected $casts = [
        'status' => 'boolean',
        'operational_area_enabled' => 'boolean',
    ];

    protected $appends = [
        'logo_url',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }

        return url('/storage/' . ltrim($this->logo_path, '/'));
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function featuredBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
