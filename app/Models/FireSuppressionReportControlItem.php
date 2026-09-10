<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FireSuppressionReportControlItem extends Model
{
    use HasFactory;

    public const STATUSES = ['uygun', 'uygun_degil', 'uygulanamiyor'];

    protected $fillable = [
        'tenant_id',
        'report_id',
        'template_id',
        'equipment_code',
        'inventory_item_id',
        'category',
        'code',
        'section',
        'title',
        'status',
        'description',
        'sort_order',
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

    public function report(): BelongsTo
    {
        return $this->belongsTo(FireSuppressionReport::class, 'report_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FireSuppressionControlItemTemplate::class, 'template_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(FireSuppressionInventoryItem::class, 'inventory_item_id');
    }
}
