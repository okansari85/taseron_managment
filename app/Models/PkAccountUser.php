<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// PKTakip OSGB / kurumsal hesap kullanıcısı: pktakip rolü ve pasiflik (bkz. PkAccountUserService).
class PkAccountUser extends Model
{
    public const ROLES = ['yonetici', 'operasyon', 'uzman'];

    protected $fillable = ['tenant_id', 'user_id', 'role', 'deactivated_at', 'created_by'];

    protected $casts = ['deactivated_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
