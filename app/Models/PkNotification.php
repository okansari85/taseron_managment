<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

// pktakip panel bildirimi (kullanıcıya ait). Türler: kontrol_yaklasan, kontrol_geciken, bulgu_hatirlatma. link önyüz yolu.
class PkNotification extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'type', 'title', 'body', 'link', 'payload', 'dedupe_key', 'read_at'];

    protected $casts = ['payload' => 'array', 'read_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));
    }
}
