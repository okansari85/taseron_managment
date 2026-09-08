<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LocationEmergencyEquipment extends Model
{
    use HasFactory;

    protected $table = 'location_emergency_equipment';

    protected $fillable = [
        'tenant_id',
        'location_business_entity_id',
        'equipment_type_id',
        'code',
        'location_note',
        'install_date',
        'status',
        'is_active',
    ];

    protected $casts = [
        'install_date' => 'date',
        'is_active' => 'boolean',
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

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentType::class, 'equipment_type_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(EmergencyEquipmentInspection::class);
    }

    public function latestInspection(): HasOne
    {
        return $this->hasOne(EmergencyEquipmentInspection::class)->latestOfMany('inspected_at');
    }
}
