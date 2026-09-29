<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Tesisatın içindeki sistem; sonucu raporlardan (pk_installation_report_systems) gelir.
class PkInstallationSystem extends Model
{
    protected $fillable = ['tenant_id', 'pk_installation_id', 'system_id', 'name', 'sort_order'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(PkInstallation::class, 'pk_installation_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(PeriodicInstallationSystem::class, 'system_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(PkInstallationReportSystem::class, 'pk_installation_system_id');
    }
}
