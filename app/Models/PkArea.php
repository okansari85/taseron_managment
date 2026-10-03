<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Lokasyonun içindeki alan (pktakip): operasyonel alan hizmet veren firmanın lokasyondaki kaydına (işyeri) bağlı, ortak alan
// firmasız; alt alan tek kat (parent_id).
class PkArea extends Model
{
    protected $fillable = ['tenant_id', 'location_id', 'parent_id', 'pk_area_type_id', 'name', 'location_business_entity_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PkAreaType::class, 'pk_area_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(LocationBusinessEntity::class, 'location_business_entity_id');
    }
}
