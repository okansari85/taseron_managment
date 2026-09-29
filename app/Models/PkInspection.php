<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// pktakip ekipman kontrol kaydı (rapordan yapay zeka ile ya da elle).
class PkInspection extends Model
{
    protected $fillable = [
        'tenant_id', 'pk_equipment_id', 'control_date', 'next_control_date', 'status', 'source', 'report_file', 'report_file_name', 'report_no', 'report_hash', 'note', 'inspection_body', 'conclusion', 'findings', 'analysis', 'created_by',
    ];

    protected $casts = ['control_date' => 'date:Y-m-d', 'next_control_date' => 'date:Y-m-d', 'findings' => 'array', 'analysis' => 'array'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(PkEquipment::class, 'pk_equipment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Kayıt bir tesisat raporundan geldiyse o rapor (tesisat raporu silinince bu kayıt da silinir).
    public function installationReports(): BelongsToMany
    {
        return $this->belongsToMany(PkInstallationReport::class, 'pk_installation_report_inspections', 'pk_inspection_id', 'pk_installation_report_id');
    }

    // Kayıt bir toplu kontrol formundan (tüp) geldiyse o form (form silinince bu kayıt da silinir).
    public function bulkReports(): BelongsToMany
    {
        return $this->belongsToMany(PkBulkReport::class, 'pk_bulk_report_inspections', 'pk_inspection_id', 'pk_bulk_report_id');
    }
}
