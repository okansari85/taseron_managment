<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Bulgunun işlem geçmişi. Türler: olusturuldu, tekrar_goruldu, guncellendi, not, dosya, oneri, oneri_reddedildi, kapandi,
// yeniden_acildi, rapordan_cikarildi, hatirlatma. Kanıt dosyası 'dosya' olayında (gizli disk).
class PkFindingEvent extends Model
{
    public const USER_WORK = ['guncellendi', 'not', 'dosya', 'oneri_reddedildi', 'kapandi', 'yeniden_acildi'];

    protected $fillable = ['pk_finding_id', 'type', 'note', 'file_path', 'file_name', 'meta', 'user_id'];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
