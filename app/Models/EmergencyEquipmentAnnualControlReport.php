<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return url('/storage/' . ltrim($this->file_path, '/'));
    }
}
