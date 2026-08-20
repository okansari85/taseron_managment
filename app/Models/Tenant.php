<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected $appends = [
        'logo_url',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }

        return rtrim(config('app.url'), '/') . '/storage/' . ltrim($this->logo_path, '/');
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
