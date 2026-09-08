<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmergencyEquipmentInspection extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'location_emergency_equipment_id',
        'inspected_by_name',
        'inspected_by_user_id',
        'overall_result',
        'notes',
        'inspected_at',
    ];

    protected $casts = [
        'inspected_at' => 'datetime',
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

    public function locationEquipment(): BelongsTo
    {
        return $this->belongsTo(LocationEmergencyEquipment::class, 'location_emergency_equipment_id');
    }

    public function inspectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmergencyEquipmentInspectionItem::class, 'inspection_id');
    }
}
