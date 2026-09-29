<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Tesisatın periyodik kontrol raporu; kapsadığı sistemlerin sonuçları systems() içinde.
class PkInstallationReport extends Model
{
    protected $fillable = [
        'tenant_id', 'pk_installation_id', 'control_date', 'next_control_date', 'status', 'source', 'report_file', 'report_file_name',
        'report_no', 'report_hash', 'inspection_body', 'conclusion', 'findings', 'notes', 'analysis', 'created_by',
    ];

    protected $casts = ['control_date' => 'date:Y-m-d', 'next_control_date' => 'date:Y-m-d', 'findings' => 'array', 'analysis' => 'array'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(PkInstallation::class, 'pk_installation_id');
    }

    public function systems(): HasMany
    {
        return $this->hasMany(PkInstallationReportSystem::class, 'pk_installation_report_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Bu rapordan ekipmanlara (Ekipmanlar'daki dolap, pompa…) yazılan kontrol kayıtları.
    public function inspections(): BelongsToMany
    {
        return $this->belongsToMany(PkInspection::class, 'pk_installation_report_inspections', 'pk_installation_report_id', 'pk_inspection_id')->withTimestamps();
    }
}
