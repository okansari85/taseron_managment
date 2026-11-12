<?php

namespace App\Observers;

use App\Models\PkInspection;
use App\Models\PkInstallationReport;
use App\Models\PkInstallationReportComponent;
use App\Models\PkInstallationReportSystem;
use App\Services\PkFindingSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Bulgu Takip: rapor (ekipman kontrolü, tesisat raporu, sistem sonucu, bileşen listesi) eklenince / değişince / silinince
 * bulguları eşitler. Raporu kaydeden kod değişmez; eşitleme işlem (transaction) bitince çalışır, hata raporu kaydetmeyi bozmaz.
 * Aynı işlemde birden çok çağrı olur (rapor + sistemleri); eşitleme tekrar edilebilir, sonuç aynı.
 */
class PkFindingSourceObserver
{
    public function saved(Model $model): void
    {
        $this->queue($model, $model->wasRecentlyCreated);
    }

    public function deleted(Model $model): void
    {
        $this->queue($model, false);
    }

    private function queue(Model $model, bool $created): void
    {
        [$type, $id] = match (true) {
            $model instanceof PkInspection => ['inspection', $model->id],
            $model instanceof PkInstallationReport => ['installation_report', $model->id],
            $model instanceof PkInstallationReportSystem => ['installation_report', $model->pk_installation_report_id],
            $model instanceof PkInstallationReportComponent => ['installation_report', DB::table('pk_installation_report_systems')
                ->where('id', $model->pk_installation_report_system_id)->value('pk_installation_report_id')],
            default => [null, null],
        };
        // Sistem / bileşen satırı "yeni rapor" sayılmaz: kapatma önerisi raporun kendi kaydında.
        $created = $created && ($model instanceof PkInspection || $model instanceof PkInstallationReport);
        if ($type && $id) {
            DB::afterCommit(fn () => rescue(fn () => app(PkFindingSyncService::class)->sync($type, (int) $id, $created)));
        }
    }
}
