<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// pktakip ekipman teknik özelliği: bir raporun okuduğu ya da elle düzeltilen tek bir değer.
class PkEquipmentPropertyValue extends Model
{
    protected $fillable = [
        'tenant_id', 'pk_equipment_id', 'pk_inspection_id', 'spec_request_id', 'field', 'field_key', 'value', 'raw_field', 'raw_value',
        'is_empty', 'accepted', 'is_removed', 'source', 'report_no', 'effective_at', 'created_by',
    ];

    protected $casts = ['is_empty' => 'boolean', 'accepted' => 'boolean', 'is_removed' => 'boolean', 'effective_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
