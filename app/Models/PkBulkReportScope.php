<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Tüp kontrol formunun türü: full (tüm envanter) ya da partial (ek form). Kaydı olmayan form tüm envanter sayılır. Tenant, form
// üzerinden.
class PkBulkReportScope extends Model
{
    public const FULL = 'full';

    public const PARTIAL = 'partial';

    protected $fillable = ['pk_bulk_report_id', 'scope'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(PkBulkReport::class, 'pk_bulk_report_id');
    }
}
