<?php

namespace App\Services\Ai\PkTakip;

use App\Services\PeriodicEquipmentSpecCatalog;
use App\Services\PkInstallationService;
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
        private PkReportText $text,
        private PkReportAnalyzer $analyzer,
        private PkReportTableReader $tableReader,
        private PeriodicEquipmentSpecCatalog $catalog,
        private PkInstallationService $installations,
        private PkBulkReportReader $bulk
    ) {
    }

    /**
     * Test sayfası: ekipman raporu ($withCatalog) tüm katalogla okunur (tür, etiket, teknik özellikler — "Rapordan
     * Ekipman Tanımla" ile aynı; toplu tüp raporu da aynı yoldan); tesisat raporu tesisat kataloğuyla (tür + sistem
     * adları — "Yeni Tesisat Raporu Yükle" ile aynı).
     */
    public function analyze(string $pdfPath, string $originalName, bool $withCatalog = false): array
    {
        // Taranmış PDF: OCR ikizi (tesisat raporu dahil); görüntüden okuma yalnızca ekipman raporunda, OCR yapılamazsa.
        $upload = new UploadedFile($pdfPath, $originalName, 'application/pdf', null, true);
        $document = $this->text->read($upload, $withCatalog);

        $startedAt = microtime(true);
        try {
            $semantic = $this->analyzer->analyze($document['pages'], $withCatalog ? ['catalog' => $this->catalog->promptCatalog()] : ['installation_catalog' => PkInstallationReportReader::promptCatalog($this->installations->types())], $document['image'], $document['input'] === 'ocr');
        } catch (RuntimeException $exception) {
            $this->text->forget($document['ocr_pdf']);
            // Tek istek, tekrar deneme yok; sınır / yoğunluk hatası anlaşılır yazılır.
            throw PkGeminiError::friendly($exception);
        }

        $fixtureId = (string) Str::uuid();
        $storedPdf = self::DIR . "/{$fixtureId}.pdf";
        Storage::disk('local')->put($storedPdf, file_get_contents($pdfPath));
        // Tablolar OCR ikizinden okunur; ikiz saklanır (tabloları yeniden okumak için).
        $ocrPdf = null;
        if ($document['ocr_pdf']) {
            $ocrPdf = self::DIR . "/{$fixtureId}.ocr.pdf";
            Storage::disk('local')->put($ocrPdf, file_get_contents($document['ocr_pdf']));
            $this->text->forget($document['ocr_pdf']);
        }

        $fixture = [
            'fixture_id' => $fixtureId,
            'analyzer' => 'pk_report',
            'provider' => PkAiProvider::name(),
            'model' => PkAiProvider::model(),
            'original_file_name' => $originalName,
            'created_at' => now()->toIso8601String(),
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'page_count' => $document['image']['page_count'] ?? count($document['pages']),
            // text: PDF metni | ocr: taranmış PDF, OCR ikizinin metni (tablolar ikizden) | image: sayfalar görüntüden (tablo adımı yok).
            'input' => $document['input'],
            'ocr_pdf_path' => $ocrPdf,
            'pdf_path' => $storedPdf,
            'context' => ['mode' => $withCatalog ? 'catalog' : 'installation'],
            'semantic' => $semantic,
            // Gemini'den hemen sonra: ekipman tabloları Camelot ile okunur.
            'tables' => $document['image'] ? null : $this->readTables(Storage::disk('local')->path($ocrPdf ?? $storedPdf), $semantic, $withCatalog ? 'catalog' : 'installation'),
        ];
        $this->save($fixture);

        return $fixture;
    }

    // Kayıtlı bir analizin tablo adımını Gemini'ye tekrar gitmeden yeniden çalıştırır.
    public function rereadTables(string $fixtureId): array
    {
        $fixture = $this->get($fixtureId);
        if (($fixture['input'] ?? null) === 'image') {
            throw new RuntimeException("Görüntü PDF'te tablo adımı çalışmaz: rapor sayfa görüntülerinden okundu.");
        }
        $pdf = (string) ($fixture['ocr_pdf_path'] ?? $fixture['pdf_path'] ?? self::DIR . "/{$fixtureId}.pdf");
        if (!Storage::disk('local')->exists($pdf)) {
            throw new RuntimeException('Bu analiz için kayıtlı PDF yok.');
        }
        $fixture['tables'] = $this->readTables(Storage::disk('local')->path($pdf), (array) ($fixture['semantic'] ?? []), $fixture['context']['mode'] ?? null);
        $this->save($fixture);

        return $fixture;
    }

    /**
     * Tablo adımı: tesisat raporunda kimlik no + konum (tesisat okumasıyla aynı); toplu tüp raporunda toplu mod
     * ("Rapordan Ekipman Tanımla" ile aynı; "bulk": o modla oluşturulmuş eski fikstür); diğer ekipman raporlarında eskisi gibi.
     */
    private function readTables(string $pdfPath, array $semantic, ?string $mode): array
    {
        return match (true) {
            $mode === 'installation' => $this->tableReader->read($pdfPath, $semantic, true),
            $mode === 'bulk' || PkBulkReportReader::isBulk($semantic) => $this->bulk->tables($pdfPath, $semantic),
            default => $this->tableReader->read($pdfPath, $semantic),
        };
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
            // catalog: ekipman raporu (katalogla) | installation: tesisat raporu | eski fikstürlerde null.
            'context' => $fixture['context'] ?? null,
            'provider' => $fixture['provider'] ?? null,
            'model' => $fixture['model'] ?? null,
            'input' => $fixture['input'] ?? 'text',
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
