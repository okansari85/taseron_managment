<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'scope_type',
        'scope_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTenant(): bool
    {
        return $this->scope_type === 'tenant';
    }

    public function isOrganization(): bool
    {
        return $this->scope_type === 'organization';
    }

    public function isLocation(): bool
    {
        return $this->scope_type === 'location';
    }
}
