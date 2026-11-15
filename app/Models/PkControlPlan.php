<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// pktakip kontrol planı: lokasyonda planlanan kontrol günü; işyeri, ekipman türü, ekipmanlar ve tesisat isteğe bağlı.
class PkControlPlan extends Model
{
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'location_id', 'workplace_id', 'equipment_type_id', 'equipment_ids', 'installation_id', 'date', 'title', 'note', 'created_by'];

    protected $casts = ['equipment_ids' => 'array', 'date' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(LocationBusinessEntity::class, 'workplace_id');
    }

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(PeriodicEquipmentType::class, 'equipment_type_id');
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(PkInstallation::class, 'installation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
