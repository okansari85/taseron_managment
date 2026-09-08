<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'contractor_id',
        'organization_id',
        'location_id',
        'title',
        'description',
        'requested_date',
        'proposed_date',
        'status',
        'requested_by_name',
        'requested_by_user_id',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'proposed_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
