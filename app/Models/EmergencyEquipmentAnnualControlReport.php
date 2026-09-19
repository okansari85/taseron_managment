<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmergencyEquipmentAnnualControlReport extends Model
{
    use HasFactory;

    public const RESULTS = ['uygun', 'uygun_degil'];

    protected $fillable = [
        'tenant_id',
        'location_business_entity_id',
        'control_date',
        'next_control_date',
        'result',
        'company_name',
        'file_path',
        'file_name',
        'uploaded_by_user_id',
        'notes',
    ];

    protected $casts = [
        'control_date' => 'date',
        'next_control_date' => 'date',
    ];

    protected $appends = ['file_url'];

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

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(
            LocationEmergencyEquipment::class,
            'emergency_equipment_annual_control_items',
            'report_id',
            'location_emergency_equipment_id'
        )->withPivot(['result', 'note'])->withTimestamps();
    }

    // Rapor genelindeki (tek bir cihaza değil) kriterler - örn. YSC
    // formunun "Genel Tespit Ve Değerlendirme Soruları" bölümü.
    public function generalItems(): HasMany
    {
        return $this->hasMany(EmergencyEquipmentAnnualControlGeneralItem::class, 'report_id');
    }

    // Tek bir ekipmana (tüpe) ait kriterler - örn. AKTAŞ formunun her tüp
    // için tekrarlayan 7 sütunu. Pivot'un (equipment() ilişkisi) tek bir
    // result/note alanı, ekipman başına birden fazla gerçek kriter olunca
    // yetersiz kalıyor - bu ilişki granüler kırılımı taşır.
    public function equipmentItems(): HasMany
    {
        return $this->hasMany(EmergencyEquipmentAnnualControlEquipmentItem::class, 'report_id');
    }

    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return url('/storage/' . ltrim($this->file_path, '/'));
    }
}
