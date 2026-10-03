<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Operasyonel birim (pktakip): işyerinin (firmanın lokasyondaki kaydı) içinde ayrı yönetilen birim; kullandığı fiziksel alanlar
// pk_operational_unit_areas'ta (birden çok).
class PkOperationalUnit extends Model
{
    protected $fillable = ['tenant_id', 'location_business_entity_id', 'name', 'manager', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(LocationBusinessEntity::class, 'location_business_entity_id');
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(PkArea::class, 'pk_operational_unit_areas', 'pk_operational_unit_id', 'pk_area_id')->withTimestamps();
    }
}
