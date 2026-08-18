<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company_type',
    ];

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(
            Organization::class,
            'organization_companies'
        )->withTimestamps();
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(
            Location::class,
            'locations_companies'
        )
        ->withPivot([
            'nace_code',
            'hazard_class',
            'sgk_workplace_number',
        ])
        ->withTimestamps();
    }
}