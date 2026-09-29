<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Kataloğa ekleme talebi: teknik özellik (kind=spec) ya da tesisat sistemi (kind=system). Yetkili tüm kiracıların
// taleplerini görür; bu yüzden TenantScope yok, uzman tarafında sorgular tenant_id ile açıkça süzülür.
class PeriodicEquipmentSpecRequest extends Model
{
    protected $fillable = [
        'tenant_id', 'kind', 'equipment_type_id', 'pk_equipment_id', 'pk_inspection_id', 'pk_installation_report_id', 'name', 'unit',
        'raw_label', 'raw_value', 'payload', 'status', 'spec_id', 'installation_system_id', 'decision_note', 'requested_by', 'decided_by', 'decided_at',
    ];

    protected $casts = ['decided_at' => 'datetime', 'payload' => 'array'];

    // Sistem talebinin raporu (yetkili başka kiracının talebini de karara bağlar: kiracı süzgeci yok).
    public function installationReport(): BelongsTo
    {
        return $this->belongsTo(PkInstallationReport::class, 'pk_installation_report_id')->withoutGlobalScopes();
    }

    public function installationSystem(): BelongsTo
    {
        return $this->belongsTo(PeriodicInstallationSystem::class, 'installation_system_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PeriodicEquipmentType::class, 'equipment_type_id');
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(PeriodicEquipmentSpec::class, 'spec_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
