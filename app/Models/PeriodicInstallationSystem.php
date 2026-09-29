<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// pktakip tesisat kataloğu: bir tesisat türünün içindeki sistem (ör. Yangın Tesisatı → Yangın Pompa İstasyonu).
// Tenant'a bağlı değildir.
class PeriodicInstallationSystem extends Model
{
    protected $fillable = ['installation_type_id', 'slug', 'name', 'equipment_type_id', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function installationType(): BelongsTo
    {
        return $this->belongsTo(PeriodicEquipmentType::class, 'installation_type_id');
    }

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(PeriodicEquipmentType::class, 'equipment_type_id');
    }

    // Sistemin ekipman türleri (Ekipmanlar'daki türler); her tür tek bir sisteme ait.
    public function equipmentTypes(): BelongsToMany
    {
        return $this->belongsToMany(PeriodicEquipmentType::class, 'periodic_system_equipment_types', 'installation_system_id', 'equipment_type_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
