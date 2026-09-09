<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LocationEmergencyEquipment extends Model
{
    use HasFactory;

    protected $table = 'location_emergency_equipment';

    protected $fillable = [
        'tenant_id',
        'location_business_entity_id',
        'equipment_type_id',
        'code',
        'location_note',
        'install_date',
        'status',
        'is_active',
        'last_fill_date',
        'next_fill_date',
        'last_annual_maintenance_date',
        'next_annual_maintenance_date',
        'service_company',
    ];

    protected $casts = [
        'install_date' => 'date',
        'last_fill_date' => 'date',
        'next_fill_date' => 'date',
        'last_annual_maintenance_date' => 'date',
        'next_annual_maintenance_date' => 'date',
        'is_active' => 'boolean',
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

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentType::class, 'equipment_type_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(EmergencyEquipmentInspection::class);
    }

    public function latestInspection(): HasOne
    {
        return $this->hasOne(EmergencyEquipmentInspection::class)->latestOfMany('inspected_at');
    }

    // Yıllık Periyodik Kontrol — Aylık Kontrol'den (inspections()) tamamen
    // bağımsız, akredite firma tarafından yapılan ve rapora bağlı kontrol
    // geçmişi (YSC mimari talimatı section 3/5). Bu ilişki inspections()'a
    // dokunmadan eklendi.
    public function annualControlReports(): BelongsToMany
    {
        return $this->belongsToMany(
            EmergencyEquipmentAnnualControlReport::class,
            'emergency_equipment_annual_control_items',
            'location_emergency_equipment_id',
            'report_id'
        )->withPivot(['result', 'note'])->withTimestamps()->orderByDesc('emergency_equipment_annual_control_reports.control_date');
    }

    // Kontrol tarihine göre deterministik yıllık kontrol durumu — mevcut
    // next_annual_maintenance_date alanını KORUYARAK üzerine ek bir görünüm
    // sağlar (section 19'daki "Yıllık Kontroller: X Güncel, Y Yaklaşıyor"
    // özetinin dayandığı kural).
    public function getAnnualControlStatusAttribute(): ?string
    {
        return $this->resolvePeriodicStatus($this->next_annual_maintenance_date);
    }

    // 4 Yıllık Dolum durumu — aynı deterministik kural, next_fill_date'e göre.
    public function getFillStatusAttribute(): ?string
    {
        return $this->resolvePeriodicStatus($this->next_fill_date);
    }

    private function resolvePeriodicStatus($nextDate): ?string
    {
        if ($nextDate === null) {
            return null;
        }

        $now = now()->startOfDay();
        $next = $nextDate->copy()->startOfDay();

        if ($next->lt($now)) {
            return 'gecikmis';
        }

        if ($next->lte($now->copy()->addDays(30))) {
            return 'yaklasiyor';
        }

        return 'guncel';
    }

    protected $appends = ['annual_control_status', 'fill_status'];
}
