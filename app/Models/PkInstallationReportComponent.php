<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Bir kontrolde bir sistemin rapordaki ekipmanları (elektrik: panolar, röleler, ölçüm noktaları). Ekipmanlar'a kayıt
// değildir; raporda okunduğu gibi saklanır. Tenant, sistem sonucu → rapor üzerinden.
class PkInstallationReportComponent extends Model
{
    protected $fillable = ['pk_installation_report_system_id', 'components'];

    protected $casts = ['components' => 'array'];

    public function result(): BelongsTo
    {
        return $this->belongsTo(PkInstallationReportSystem::class, 'pk_installation_report_system_id');
    }
}
