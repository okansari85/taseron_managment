<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(
            Organization::class,
            'organization_locations'
        )->withTimestamps();
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
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