<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldFinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'location_business_entity_id',
        'category',
        'location_note',
        'description',
        'severity',
        'status',
        'reported_by_user_id',
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

    public function locationBusinessEntity(): BelongsTo
    {
        return $this->belongsTo(LocationBusinessEntity::class);
    }

    public function reportedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(FieldFindingPhoto::class)->orderBy('order_no');
    }
}
