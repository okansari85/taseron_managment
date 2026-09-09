<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FireSuppressionReportFinding extends Model
{
    use HasFactory;

    public const SCOPES = ['all', 'specific', 'area', 'unknown'];
    public const STATUSES = ['open', 'closed'];

    protected $fillable = [
        'tenant_id',
        'report_id',
        'category',
        'control_item',
        'description',
        'scope',
        'area_note',
        'status',
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

    public function affectedItems(): BelongsToMany
    {
        return $this->belongsToMany(
            FireSuppressionInventoryItem::class,
            'fire_suppression_report_finding_items',
            'finding_id',
            'inventory_item_id'
        )->withTimestamps();
    }
}
