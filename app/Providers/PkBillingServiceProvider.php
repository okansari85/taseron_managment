<?php

namespace App\Providers;

use App\Models\PkReportAnalysis;
use App\Services\PkBillingService;
use Illuminate\Support\ServiceProvider;

// pktakip kredi: Analiz geçmişine başarılı okuma satırı yazılınca kredisi düşülür (okuma kodu değişmez). Kredi yazılamazsa
// okuma bozulmasın: hata yutulur, loglanır.
class PkBillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PkReportAnalysis::created(function (PkReportAnalysis $analysis) {
            rescue(fn () => app(PkBillingService::class)->chargeReading($analysis));
        });
    }
}
