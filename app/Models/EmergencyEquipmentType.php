<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmergencyEquipmentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'description',
        'capacity_kg',
        'tip',
        'inspection_frequency_days',
        'is_active',
    ];

    protected $casts = [
        'capacity_kg' => 'decimal:2',
        'inspection_frequency_days' => 'integer',
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

    public function checklistItems(): HasMany
    {
        return $this->hasMany(EmergencyEquipmentTypeChecklistItem::class, 'equipment_type_id');
    }

    public function locationEquipment(): HasMany
    {
        return $this->hasMany(LocationEmergencyEquipment::class, 'equipment_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function tipOptions(): HasMany
    {
        return $this->hasMany(EmergencyEquipmentTypeTipOption::class, 'equipment_type_id')->orderBy('sort_order');
    }
}
