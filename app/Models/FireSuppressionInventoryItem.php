<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FireSuppressionInventoryItem extends Model
{
    use HasFactory;

    public const CATEGORIES = [
        'yangin_dolabi',
        'sprinkler',
        'hidrant',
        'yangin_pompasi',
        'su_deposu',
        'gazli_sondurme',
        'diger',
    ];

    public const COMPLIANCE_STATUSES = ['uygun', 'uygun_degil'];

    protected $fillable = [
        'tenant_id',
        'location_business_entity_id',
        'category',
        'code',
        'location_note',
        'brand',
        'model',
        'serial_no',
        'is_active',
        'last_control_date',
        'next_control_date',
        'compliance_status',
        'open_nonconformity_count',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_control_date' => 'date',
        'next_control_date' => 'date',
        'open_nonconformity_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(
            app(TenantScope::class)
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function locationBusinessEntity(): BelongsTo
    {
        return $this->belongsTo(LocationBusinessEntity::class);
    }

    // Bu kalemi kontrol etmiş raporlar (periyodik kontrol geçmişi — section 6).
    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(
            FireSuppressionReport::class,
            'fire_suppression_report_inventory_items',
            'inventory_item_id',
            'report_id'
        )->withTimestamps()->orderByDesc('fire_suppression_reports.report_date');
    }

    // Kontrol tarihine göre deterministik durum — section 8/13'teki "backend'de
    // açık ve deterministik kurala bağlanmalı" kuralı. compliance_status'tan
    // (tesisat uygunluğu) BAĞIMSIZ bir kavramdır: bir sistem kontrolü zamanında
    // yapılmış olabilir ama sonucu uygunsuz çıkmış olabilir, ya da tam tersi
    // kontrol gecikmiş olabilir ama son bilinen sonuç uygundur.
    public function getPeriodicControlStatusAttribute(): ?string
    {
        if ($this->next_control_date === null) {
            return null;
        }

        $now = now()->startOfDay();
        $next = $this->next_control_date->copy()->startOfDay();

        if ($next->lt($now)) {
            return 'kontrol_gecikmis';
        }

        if ($next->lte($now->copy()->addDays(30))) {
            return 'kontrol_yaklasiyor';
        }

        return 'kontrolu_gecerli';
    }

    protected $appends = ['periodic_control_status'];
}
