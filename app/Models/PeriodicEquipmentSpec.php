<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// pktakip ekipman teknik özellik kataloğu; tenant'a bağlı değildir, tüm kullanıcılarda ortaktır.
// equipment_type_id boş = her türde ortak özellik.
class PeriodicEquipmentSpec extends Model
{
    protected $fillable = ['equipment_type_id', 'key', 'name', 'unit', 'aliases', 'sort_order', 'is_active', 'created_by'];

    protected $casts = ['aliases' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(PeriodicEquipmentType::class, 'equipment_type_id');
    }
}
