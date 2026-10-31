<?php

namespace App\Console\Commands;

use App\Models\PkInstallationReport;
use Illuminate\Console\Command;

/**
 * Yapay zekanın okuduğu bulgular ile uzmanın kaydettikleri arasındaki fark (rapor kaydında ikisi de var, yeni tablo gerekmez): hangi bulguyu uzman
 * ekledi, hangisini sildi. Salt okunur; bütün kuruluşlar (yönetici komutu), tek sefer inceleme için.
 */
class PkReadCorrections extends Command
{
    protected $signature = 'pk:duzeltmeler';

    protected $description = 'Yapay zeka okuması ile uzman kaydı arasındaki bulgu farkları (salt okunur)';

    public function handle(): int
    {
        $total = 0;
        foreach (PkInstallationReport::withoutGlobalScopes()->with('systems')->whereNotNull('analysis')->orderBy('id')->get() as $report) {
            $summary = (array) (($report->analysis ?? [])['summary'] ?? []);
            if (!$summary) {
                continue;
            }
            $read = [...(array) ($summary['general_findings'] ?? []), ...array_merge(...array_map(fn ($system) => (array) ($system['findings'] ?? []), (array) ($summary['systems'] ?? [])) ?: [[]])];
            $saved = [...(array) ($report->findings ?? []), ...$report->systems->flatMap(fn ($system) => (array) ($system->findings ?? []))->all()];
            $total++;
            $this->line("#{$report->id}: okunan " . count($read) . ', kayıtlı ' . count($saved));
            foreach (array_diff($saved, $read) as $text) {
                $this->line('  + uzman ekledi: ' . $text);
            }
            foreach (array_diff($read, $saved) as $text) {
                $this->line('  - uzman sildi: ' . $text);
            }
        }
        $this->info("Karşılaştırılan rapor: {$total}");

        return self::SUCCESS;
    }
}
