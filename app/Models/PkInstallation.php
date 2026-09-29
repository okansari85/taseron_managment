<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// pktakip tesisatı (ör. Yangın Tesisatı). location_business_entity_id boşsa lokasyon geneli.
class PkInstallation extends Model
{
    protected $fillable = ['tenant_id', 'location_id', 'location_business_entity_id', 'installation_type_id', 'name', 'notes', 'created_by'];

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
        return $this->belongsTo(PeriodicEquipmentType::class, 'installation_type_id');
    }

    public function systems(): HasMany
    {
        return $this->hasMany(PkInstallationSystem::class, 'pk_installation_id')->orderBy('sort_order')->orderBy('id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(PkInstallationReport::class, 'pk_installation_id');
    }

    // Son kontrol: tesisatın genel durumu ve tarihleri bu raporun genel sonucundan.
    public function latestReport(): HasOne
    {
        return $this->hasOne(PkInstallationReport::class, 'pk_installation_id')->ofMany([
            'control_date' => 'max',
            'id' => 'max',
        ]);
    }
}
