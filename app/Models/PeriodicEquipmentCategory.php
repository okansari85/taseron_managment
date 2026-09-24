<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// pktakip ekipman kataloğu kategorisi; tenant'a bağlı değildir, tüm kullanıcılarda ortaktır.
class PeriodicEquipmentCategory extends Model
{
    protected $fillable = ['slug', 'name', 'kind', 'icon', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function types(): HasMany
    {
        return $this->hasMany(PeriodicEquipmentType::class, 'category_id');
    }
}
