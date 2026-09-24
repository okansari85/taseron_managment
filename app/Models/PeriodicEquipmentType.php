<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// pktakip ekipman kataloğu türü; tenant'a bağlı değildir, tüm kullanıcılarda ortaktır.
class PeriodicEquipmentType extends Model
{
    protected $fillable = ['category_id', 'slug', 'name', 'default_period_months', 'default_scope', 'regulation_note', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'default_period_months' => 'integer'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PeriodicEquipmentCategory::class, 'category_id');
    }
}
