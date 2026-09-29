<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Bir raporda bir sistemin sonucu, bulguları ve ekipman özeti. Tenant, rapor üzerinden.
class PkInstallationReportSystem extends Model
{
    protected $fillable = ['pk_installation_report_id', 'pk_installation_system_id', 'status', 'source_names', 'findings', 'equipment_summary'];

    protected $casts = ['source_names' => 'array', 'findings' => 'array', 'equipment_summary' => 'array'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(PkInstallationReport::class, 'pk_installation_report_id');
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(PkInstallationSystem::class, 'pk_installation_system_id');
    }
}
