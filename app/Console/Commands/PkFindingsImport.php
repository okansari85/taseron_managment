<?php

namespace App\Console\Commands;

use App\Services\PkFindingSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bulgu Takip (2026-11-12): Bulgu Takip'ten önce kaydedilmiş raporların bulgularını bir kez aktarır. Yalnız güncel olanlar:
 * her ekipmanın en son kontrolü; tesisatta her konunun (genel / her sistem) en son raporu. Tekrar çalıştırmak güvenli
 * (aynı rapor ikinci kez bağlanmaz).
 */
class PkFindingsImport extends Command
{
    protected $signature = 'pk:findings-import {--tenant= : Yalnız bu kiracı}';

    protected $description = 'Kayıtlı raporların (en son olanlar) bulgularını Bulgu Takip\'e aktarır';

    public function handle(PkFindingSyncService $sync): int
    {
        $tenant = $this->option('tenant');
        $inspections = 0;
        DB::table('pk_equipment')->when($tenant, fn ($q, $id) => $q->where('tenant_id', $id))->whereNotNull('last_inspection_id')
            ->orderBy('id')->select(['id', 'last_inspection_id'])->chunkById(500, function ($rows) use ($sync, &$inspections) {
                foreach ($rows as $row) {
                    $sync->sync('inspection', (int) $row->last_inspection_id);
                    $inspections++;
                }
            });

        $reports = 0;
        DB::table('pk_installations')->when($tenant, fn ($q, $id) => $q->where('tenant_id', $id))
            ->orderBy('id')->select('id')->chunkById(200, function ($rows) use ($sync, &$reports) {
                foreach ($rows as $row) {
                    // En yeniden eskiye: her konu (genel bulgular / sistem) ilk rastladığı, yani en son raporundan alınır.
                    $claimed = [];
                    $list = DB::table('pk_installation_reports')->where('pk_installation_id', $row->id)
                        ->orderByDesc('control_date')->orderByDesc('id')->get(['id', 'report_scope']);
                    foreach ($list as $report) {
                        $subjects = DB::table('pk_installation_report_systems')->where('pk_installation_report_id', $report->id)
                            ->pluck('pk_installation_system_id')->map(fn ($id) => 's:' . $id)->all();
                        if ($report->report_scope === null) {
                            $subjects[] = 'g:' . $row->id;
                        }
                        $allowed = array_values(array_diff($subjects, $claimed));
                        $claimed = [...$claimed, ...$subjects];
                        if ($allowed) {
                            $sync->sync('installation_report', (int) $report->id, false, $allowed);
                            $reports++;
                        }
                    }
                }
            });

        $this->info("{$inspections} ekipman kontrolü ve {$reports} tesisat raporu tarandı; bulgu sayısı: " . DB::table('pk_findings')->when($tenant, fn ($q, $id) => $q->where('tenant_id', $id))->count());

        return self::SUCCESS;
    }
}
