<?php

namespace App\Console\Commands;

use App\Services\Ai\PkTakip\PkSingleEquipmentReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Deneme: tek ekipman raporunu kısa talimatla okur (PkSingleEquipmentReader), sonucu ekrana ve
 * storage/app/private/pk-kisa-talimat/ altına yazar. Veritabanına ve mevcut akışa dokunmaz; yapay zekaya tek istek atar.
 */
class PkTryShortPrompt extends Command
{
    protected $signature = 'pk:kisa-talimat-dene
        {pdf : Tek ekipman raporu (PDF)}
        {--type=forklift : Katalogdaki tür (slug)}
        {--provider= : gemini | openai | nvidia | local (boşsa Ayarlar\'daki)}';

    protected $description = 'Deneme: tek ekipman raporunu kısa talimatla okur (mevcut talimat değişmez)';

    public function handle(PkSingleEquipmentReader $reader): int
    {
        $path = (string) $this->argument('pdf');
        if (!is_file($path)) {
            $this->error("PDF yok: {$path}");
            return self::FAILURE;
        }

        $this->info(basename($path) . ' okunuyor...');
        try {
            $result = $reader->read(realpath($path), (string) $this->option('type'), $this->option('provider') ?: null);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $data = (array) ($result['semantic']['extracted_data'] ?? []);
        $this->info("Bitti: {$result['duration_s']} sn ({$result['provider']} · {$result['model']})");
        foreach ((array) ($data['report_information'] ?? []) as $field) {
            $this->line(sprintf('  %-14s %s', $field['key'] ?? '?', $field['value'] ?? '—'));
        }
        $this->line('  Genel sonuç: ' . ($data['overall_result']['status'] ?? '—') . ' — ' . ($data['overall_result']['text'] ?? ''));
        $findings = (array) ($data['findings'] ?? []);
        $this->line('  Bulgu: ' . count($findings));
        foreach ($findings as $finding) {
            $this->line('    - ' . ($finding['description'] ?? ''));
        }

        $file = 'pk-kisa-talimat/' . pathinfo($path, PATHINFO_FILENAME) . '-' . $result['provider'] . '-' . now()->format('His') . '.json';
        Storage::disk('local')->put($file, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('JSON: ' . Storage::disk('local')->path($file));

        return self::SUCCESS;
    }
}
