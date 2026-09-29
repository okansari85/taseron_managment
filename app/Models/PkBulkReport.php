<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Toplu kontrol formu (yangın tüpleri): rapor, genel sonuç, bölümler ve genel bulgular; tüplere açılan kontrol kayıtları inspections().
class PkBulkReport extends Model
{
    protected $fillable = [
        'tenant_id', 'location_id', 'location_business_entity_id', 'equipment_type_id', 'control_date', 'next_control_date', 'status', 'source',
        'report_file', 'report_file_name', 'report_no', 'report_hash', 'inspection_body', 'conclusion', 'systems', 'findings', 'analysis', 'created_by',
    ];

    protected $casts = [
        'control_date' => 'date:Y-m-d',
        'next_control_date' => 'date:Y-m-d',
        'systems' => 'array',
        'findings' => 'array',
        'analysis' => 'array',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PeriodicEquipmentType::class, 'equipment_type_id');
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(LocationBusinessEntity::class, 'location_business_entity_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Bu formla tüplere açılan kontrol kayıtları.
    public function inspections(): BelongsToMany
    {
        return $this->belongsToMany(PkInspection::class, 'pk_bulk_report_inspections', 'pk_bulk_report_id', 'pk_inspection_id')->withTimestamps();
    }
}
