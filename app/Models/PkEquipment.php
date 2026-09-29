<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// pktakip periyodik kontrole tabi ekipman. location_business_entity_id boşsa lokasyon geneli.
class PkEquipment extends Model
{
    protected $table = 'pk_equipment';

    protected $fillable = [
        'tenant_id', 'location_id', 'location_business_entity_id', 'equipment_type_id', 'variant',
        'name', 'code', 'serial_no', 'brand', 'model', 'place', 'properties', 'notes', 'photo_path', 'is_active',
        'deactivated_at', 'deactivation_reason', 'deactivation_note', 'created_by',
    ];

    protected $casts = ['properties' => 'array', 'is_active' => 'boolean', 'deactivated_at' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(LocationBusinessEntity::class, 'location_business_entity_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PeriodicEquipmentType::class, 'equipment_type_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(PkInspection::class, 'pk_equipment_id');
    }

    // En son kontrol: kontrol tarihi en yeni olan (aynı günse en son girilen).
    public function latestInspection(): HasOne
    {
        return $this->hasOne(PkInspection::class, 'pk_equipment_id')->ofMany([
            'control_date' => 'max',
            'id' => 'max',
        ]);
    }
}
