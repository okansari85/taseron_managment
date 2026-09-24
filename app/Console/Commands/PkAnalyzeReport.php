<?php

namespace App\Console\Commands;

use App\Services\Ai\PkTakip\PkReportFixtureStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * pktakip rapor algılamasını (PkReportAnalyzer) bir PDF üzerinde çalıştırır ve sonucu fixture olarak saklar.
 * Uzman panelindeki Test ekranı ile aynı PkReportFixtureStore'u kullanır.
 */
class PkAnalyzeReport extends Command
{
    protected $signature = 'pk:analyze-report
        {pdf? : Analiz edilecek PDF dosyasının yolu}
        {--legacy= : PDF yerine eski yangın fixture\'ının (fixture_id) PDF\'ini kullan}
        {--list : Kayıtlı pktakip fixture\'larını listele}';

    protected $description = 'pktakip rapor algılamasını (kriterler hariç) bir PDF üzerinde çalıştırır ve sonucu fixture olarak kaydeder';

    public function handle(PkReportFixtureStore $store): int
    {
        if ($this->option('list')) {
            $rows = array_map(fn ($f) => [$f['fixture_id'], $f['original_file_name'], $f['report_category'], $f['created_at']], $store->list());
            $rows ? $this->table(['Fixture', 'Dosya', 'Tip', 'Tarih'], $rows) : $this->line('Henüz pktakip fixture\'ı yok.');
            return self::SUCCESS;
        }

        try {
            if ($legacyId = $this->option('legacy')) {
                [$pdfPath, $originalName] = $store->legacyPdf($legacyId);
            } elseif (($path = $this->argument('pdf')) && is_file($path)) {
                [$pdfPath, $originalName] = [realpath($path), basename($path)];
            } else {
                $this->error('PDF yolu verin ya da --legacy=<fixture_id> kullanın. Eski fixture\'lar:');
                $this->table(['Eski fixture', 'Dosya'], array_map(fn ($f) => [$f['fixture_id'], $f['original_file_name']], $store->legacyList()));
                return self::FAILURE;
            }

            $this->info("PDF: {$originalName} - Gemini ile analiz ediliyor (1-3 dakika sürebilir)...");
            $fixture = $store->analyze($pdfPath, $originalName);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info("Analiz tamamlandı ({$fixture['duration_s']} sn). Fixture: {$fixture['fixture_id']}");
        $this->line('JSON: ' . Storage::disk('local')->path(PkReportFixtureStore::DIR . "/{$fixture['fixture_id']}.json"));

        return self::SUCCESS;
    }
}
