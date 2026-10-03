<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Tüp kontrol formunun eşleştirme bekleyen satırı (Ekipmanlar'da karşılığı seçilmemiş tüp). Tenant, form üzerinden.
class PkBulkReportPendingRow extends Model
{
    protected $fillable = ['pk_bulk_report_id', 'row'];

    protected $casts = ['row' => 'array'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(PkBulkReport::class, 'pk_bulk_report_id');
    }
}
