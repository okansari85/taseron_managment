<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyEquipmentInspectionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'inspection_id',
        'checklist_item_id',
        'note',
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

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentInspection::class, 'inspection_id');
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipmentTypeChecklistItem::class, 'checklist_item_id');
    }
}
