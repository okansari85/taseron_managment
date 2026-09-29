<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// pktakip genel ayarı (kiracıya bağlı değil): anahtar + JSON değer.
class PkSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = ['value' => 'array'];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
