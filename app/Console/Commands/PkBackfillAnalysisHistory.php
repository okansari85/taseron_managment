<?php

namespace App\Console\Commands;

use App\Services\PkReportAnalysisHistory;
use Illuminate\Console\Command;

/**
 * Analiz geçmişi özelliğinden önceki okumaları geçmişe ekler: yalnızca kaydedilmiş olanlar (analizi ekipman kontrol
 * kaydında ya da tesisat raporunda saklananlar); test verisiyle yapılanlar atlanır. Tekrar çalıştırılabilir.
 */
class PkBackfillAnalysisHistory extends Command
{
    protected $signature = 'pk:backfill-analysis-history';

    protected $description = 'pktakip: kaydedilmiş eski yapay zeka okumalarını analiz geçmişine ekler';

    public function handle(PkReportAnalysisHistory $history): int
    {
        $added = $history->backfill();
        $this->info("Eklendi: {$added['equipment']} ekipman raporu, {$added['installation']} tesisat raporu.");

        return self::SUCCESS;
    }
}
