<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// pktakip Bulgu Takip: rapordaki bir bulgu (ekipmanın ya da tesisatın / sisteminin). Kaynak raporlar pk_finding_sources'ta,
// geçmiş ve kanıt dosyaları pk_finding_events'te. Durumlar: acik, devam, tekrar_kontrol, kapandi.
class PkFinding extends Model
{
    public const STATUSES = ['acik', 'devam', 'tekrar_kontrol', 'kapandi'];

    protected $fillable = [
        'tenant_id', 'pk_equipment_id', 'pk_installation_id', 'pk_installation_system_id', 'component', 'location_id',
        'location_business_entity_id', 'text', 'text_key', 'work', 'responsible_user_id', 'due_date', 'recheck_date', 'status',
        'last_seen_date', 'close_suggested_by', 'close_suggested_date', 'closed_at', 'closed_by',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d', 'recheck_date' => 'date:Y-m-d', 'last_seen_date' => 'date:Y-m-d',
        'close_suggested_date' => 'date:Y-m-d', 'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(PkEquipment::class, 'pk_equipment_id');
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(PkInstallation::class, 'pk_installation_id');
    }

    public function installationSystem(): BelongsTo
    {
        return $this->belongsTo(PkInstallationSystem::class, 'pk_installation_system_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(LocationBusinessEntity::class, 'location_business_entity_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(PkFindingSource::class, 'pk_finding_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PkFindingEvent::class, 'pk_finding_id');
    }
}
