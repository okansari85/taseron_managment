<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// FireSuppressionAnalysisProgress'in kalıcı depolama katmanı — bilinçli
// olarak TenantScope YOK (bkz. migration'daki not). Bu model doğrudan
// başka hiçbir yerden kullanılmamalı, sadece FireSuppressionAnalysisProgress
// üzerinden — dış dünyaya (Job/Controller/Frontend) hâlâ o servisin
// değişmeyen public arayüzü görünüyor.
class FireSuppressionAnalysis extends Model
{
    protected $fillable = [
        'analysis_id',
        'status',
        'current_stage',
        'current_label',
        'current_page',
        'total_pages',
        'started_at',
        'finished_at',
        'error',
        'result',
        'events',
    ];

    protected $casts = [
        'result' => 'array',
        'events' => 'array',
    ];
}
