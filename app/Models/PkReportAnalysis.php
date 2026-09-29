<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// pktakip analiz geçmişi: yapay zeka ile yapılan bir rapor okuması (kaydedilmiş, kaydedilmemiş ya da okunamamış).
class PkReportAnalysis extends Model
{
    protected $fillable = [
        'uuid', 'tenant_id', 'user_id', 'location_id', 'kind', 'status', 'pk_equipment_id', 'pk_inspection_id', 'pk_installation_id',
        'pk_installation_report_id', 'pk_bulk_report_id', 'file_name', 'file_path', 'file_hash', 'input', 'provider', 'model', 'duration_s', 'input_tokens',
        'output_tokens', 'detected_type', 'report_no', 'control_date', 'overall_status', 'summary', 'semantic', 'tables', 'error', 'saved_at',
    ];

    protected $casts = [
        'control_date' => 'date:Y-m-d',
        'duration_s' => 'float',
        'summary' => 'array',
        'semantic' => 'array',
        'tables' => 'array',
        'saved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(PkEquipment::class, 'pk_equipment_id');
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(PkInstallation::class, 'pk_installation_id');
    }

    public function bulkReport(): BelongsTo
    {
        return $this->belongsTo(PkBulkReport::class, 'pk_bulk_report_id');
    }
}
