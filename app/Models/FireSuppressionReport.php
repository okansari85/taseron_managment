<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FireSuppressionReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'location_business_entity_id',
        'report_date',
        'report_no',
        'next_control_date',
        'covered_categories',
        'overall_result',
        'inspection_company_name',
        'file_path',
        'file_name',
        'uploaded_by_user_id',
        'notes',
    ];

    protected $casts = [
        'report_date' => 'date',
        'next_control_date' => 'date',
        'covered_categories' => 'array',
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

    public function findings(): HasMany
    {
        return $this->hasMany(FireSuppressionReportFinding::class, 'report_id');
    }

    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(
            FireSuppressionInventoryItem::class,
            'fire_suppression_report_inventory_items',
            'report_id',
            'inventory_item_id'
        )->withTimestamps();
    }

    public function controlItems(): HasMany
    {
        return $this->hasMany(FireSuppressionReportControlItem::class, 'report_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(FireSuppressionReportFile::class, 'report_id');
    }

    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return url('/storage/' . ltrim($this->file_path, '/'));
    }
}
