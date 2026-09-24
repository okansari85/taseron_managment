<?php

namespace App\Services\Ai\PkTakip;

use App\Services\Ai\PdfTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * pktakip rapor algılama fixture'ları: PkReportAnalyzer çıktısı eski akıştaki fixture biçiminde
 * (JSON + PDF kopyası, aynı kimlikle) saklanır; rapor yükleme ekranında Gemini'ye tekrar istek atmadan
 * test için kullanılır. Eski fixture klasörüne yazmaz, oradaki PDF'leri yalnızca kaynak olarak okur.
 */
class PkReportFixtureStore
{
    public const DIR = 'pk-report-fixtures';
    public const LEGACY_DIR = 'fire-suppression-gemini-fixtures';

    public function __construct(
        private PdfTextExtractor $extractor,
        private PkReportAnalyzer $analyzer,
        private PkReportTableReader $tableReader
    ) {
    }

    public function analyze(string $pdfPath, string $originalName): array
    {
        $pages = $this->extractor->extractPages(new UploadedFile($pdfPath, $originalName, 'application/pdf', null, true));

        $startedAt = microtime(true);
        $semantic = $this->analyzer->analyze($pages);

        $fixtureId = (string) Str::uuid();
        $storedPdf = self::DIR . "/{$fixtureId}.pdf";
        Storage::disk('local')->put($storedPdf, file_get_contents($pdfPath));

        $fixture = [
            'fixture_id' => $fixtureId,
            'analyzer' => 'pk_report',
            'provider' => 'gemini',
            'model' => config('services.gemini.text_model'),
            'original_file_name' => $originalName,
            'created_at' => now()->toIso8601String(),
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'page_count' => count($pages),
            'pdf_path' => $storedPdf,
            'semantic' => $semantic,
            // Gemini'den hemen sonra: ekipman tabloları Camelot ile okunur.
            'tables' => $this->tableReader->read(Storage::disk('local')->path($storedPdf), $semantic),
        ];
        $this->save($fixture);

        return $fixture;
    }

    // Kayıtlı bir analizin tablo adımını Gemini'ye tekrar gitmeden yeniden çalıştırır.
    public function rereadTables(string $fixtureId): array
    {
        $fixture = $this->get($fixtureId);
        $pdf = (string) ($fixture['pdf_path'] ?? self::DIR . "/{$fixtureId}.pdf");
        if (!Storage::disk('local')->exists($pdf)) {
            throw new RuntimeException('Bu analiz için kayıtlı PDF yok.');
        }
        $fixture['tables'] = $this->tableReader->read(Storage::disk('local')->path($pdf), (array) ($fixture['semantic'] ?? []));
        $this->save($fixture);

        return $fixture;
    }

    private function save(array $fixture): void
    {
        Storage::disk('local')->put(self::DIR . "/{$fixture['fixture_id']}.json", json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** Eski yangın fixture'ının PDF'i: [mutlak yol, orijinal dosya adı]. */
    public function legacyPdf(string $legacyId): array
    {
        $fixture = $this->read(self::LEGACY_DIR, $legacyId);
        $pdf = (string) ($fixture['pdf_path'] ?? self::LEGACY_DIR . "/{$legacyId}.pdf");
        if (!Storage::disk('local')->exists($pdf)) {
            throw new RuntimeException('Eski fixture için kayıtlı PDF yok.');
        }

        return [Storage::disk('local')->path($pdf), (string) ($fixture['original_file_name'] ?? 'rapor.pdf')];
    }

    public function get(string $fixtureId): array
    {
        return $this->read(self::DIR, $fixtureId);
    }

    public function list(): array
    {
        return $this->summaries(self::DIR, fn (array $fixture) => [
            'fixture_id' => $fixture['fixture_id'] ?? null,
            'original_file_name' => $fixture['original_file_name'] ?? null,
            'report_category' => $fixture['semantic']['extracted_data']['report_category'] ?? null,
            'overall_status' => $fixture['semantic']['extracted_data']['overall_result']['status'] ?? null,
            'system_count' => count((array) ($fixture['semantic']['template']['fire_systems']['systems'] ?? [])),
            'finding_count' => count((array) ($fixture['semantic']['extracted_data']['findings'] ?? [])),
            'equipment_summary' => $fixture['tables']['equipment_summary'] ?? null,
            'duration_s' => $fixture['duration_s'] ?? null,
            'created_at' => $fixture['created_at'] ?? null,
        ]);
    }

    public function legacyList(): array
    {
        return $this->summaries(self::LEGACY_DIR, fn (array $fixture) => [
            'fixture_id' => $fixture['fixture_id'] ?? null,
            'original_file_name' => $fixture['original_file_name'] ?? null,
            'report_category' => $fixture['semantic']['extracted_data']['report_category'] ?? null,
            'created_at' => $fixture['created_at'] ?? null,
        ]);
    }

    private function summaries(string $dir, callable $map): array
    {
        return collect(Storage::disk('local')->files($dir))
            ->filter(fn (string $path) => str_ends_with($path, '.json'))
            ->map(fn (string $path) => json_decode(Storage::disk('local')->get($path), true))
            ->filter(fn ($fixture) => is_array($fixture))
            ->map($map)
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    private function read(string $dir, string $fixtureId): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $fixtureId)) {
            throw new RuntimeException('Geçersiz fixture kimliği.');
        }
        $path = "{$dir}/{$fixtureId}.json";
        if (!Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Fixture bulunamadı.');
        }

        return json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
