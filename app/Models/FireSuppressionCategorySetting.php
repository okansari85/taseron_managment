<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

// Tenant başına, sabit sistem kategorisi (yangin_dolabi, su_deposu, ...)
// taksonomisinin ÜZERİNE binen opsiyonel bir "görünen ad" ve "bu kategori bu
// müşteride kullanılıyor mu" tercihini tutar (bkz. migration notu). Sadece
// özelleştirilen kategoriler için satır açılır — özelleştirilmeyenler
// varsayılan (FIRE_SUPPRESSION_CATEGORY_LABELS) ile gösterilir.
class FireSuppressionCategorySetting extends Model
{
    protected $fillable = ['tenant_id', 'category', 'custom_label', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }
}
