<?php

namespace App\Console\Commands;

use App\Observers\PkInspectionObserver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ekipmanların son kontrol özetini (pk_equipment.last_*) kontrol kayıtlarından yeniden hesaplar. Normalde
 * PkInspectionObserver güncel tutar; özet şüpheliyse (gözlemciyi atlayan toplu işlem vb.) elle çalıştırılır.
 */
class PkEquipmentSummary extends Command
{
    protected $signature = 'pk:equipment-summary {--tenant= : Yalnız bu kiracı}';

    protected $description = 'Ekipmanların son kontrol özetini (son durum, tarihler, bulgular) yeniden hesaplar';

    public function handle(): int
    {
        $count = 0;
        DB::table('pk_equipment')->when($this->option('tenant'), fn ($query, $tenant) => $query->where('tenant_id', $tenant))
            ->orderBy('id')->select('id')->chunkById(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    PkInspectionObserver::refresh($row->id);
                    $count++;
                }
            });
        $this->info("{$count} ekipmanın özeti güncellendi.");

        return self::SUCCESS;
    }
}
