<?php

namespace App\Services\Ai\PkTakip;

use App\Services\Ai\PdfTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Rapor PDF'i → yapay zekaya gidecek içerik, sırasıyla:
 * 1. Metin katmanı: PdfTextExtractor (smalot); smalot bazı PDF'lerin metnini çıkaramıyor (ör. e-imzalı asansör raporu:
 *    sayfa başına yalnızca imza damgası), o zaman pdfium (Camelot'un motoru, scripts/pk_pdf_text.py).
 * 2. Metin yoksa (gerçekten taranmış): OCR ile "dijital ikiz" PDF (scripts/pk_ocr_twin.py: OpenCV hücreler + Tesseract);
 *    ikizin metni yapay zekaya, ikizin kendisi Camelot'a gider — hat dijital raporla aynı.
 * 3. OCR yapılamazsa ve tek ekipman raporuysa: PDF'in kendisi görüntü olarak (yalnızca OpenAI, kısa PDF).
 * Gerçek raporların en kısa sayfası ~1.600 karakter.
 */
class PkReportText
{
    // Hiçbir sayfada bu kadar metin yoksa metin okunamamış sayılır.
    private const MIN_PAGE_CHARS = 300;

    private ?string $ocrProblem = null;

    // pdfium betiği sunucu kurulumu yüzünden çalışmadıysa (Python / paket yok): kullanıcıya "taranmış" denmez.
    private bool $readerBroken = false;

    public function __construct(private PdfTextExtractor $extractor)
    {
    }

    /**
     * ['pages' => metin sayfaları, 'image' => ?['path', 'name', 'page_count'], 'ocr_pdf' => ?ikiz PDF (geçici dosya,
     * işi bitince forget() ile silinir ya da saklanır), 'input' => 'text' | 'ocr' | 'image'].
     * $singleEquipment: tesisat raporunda görüntüden okuma yok (büyük tablolar).
     */
    public function read(UploadedFile $pdf, bool $singleEquipment = true, string $hint = ''): array
    {
        $path = (string) $pdf->getRealPath();
        ['pages' => $pages, 'page_count' => $pageCount] = $this->textPages($pdf);
        if ($pages === null && $this->readerBroken) {
            throw new RuntimeException('PDF okunamadı: sunucudaki PDF okuyucu (Python) çalışmadı. Sistem yöneticisine bildirin' . $hint . '.');
        }
        if ($pages !== null) {
            return ['pages' => $pages, 'image' => null, 'ocr_pdf' => null, 'input' => 'text'];
        }

        $ocr = $this->ocr($path, $pageCount);
        if ($ocr !== null) {
            return ['pages' => $ocr['pages'], 'image' => null, 'ocr_pdf' => $ocr['pdf'], 'input' => 'ocr'];
        }

        if (!$singleEquipment) {
            throw new RuntimeException($this->scannedMessage($hint));
        }
        $maxPages = (int) config('pktakip.image_max_pages', 10);
        if ($pageCount === null || $pageCount > $maxPages) {
            throw new RuntimeException(
                'Bu PDF görüntü olarak kaydedilmiş' . ($pageCount ? " ve {$pageCount} sayfa" : '') . "; görüntüden okuma yalnızca tek ekipman raporlarında (en fazla {$maxPages} sayfa) yapılır. Firmadan raporun orijinal PDF'ini talep edin{$hint}."
            );
        }
        if (PkAiProvider::name() !== 'openai') {
            throw new RuntimeException("Bu PDF görüntü olarak kaydedilmiş; görüntüden okuma şu an yalnızca OpenAI ile yapılıyor (Ayarlar → Yapay zeka). Firmadan raporun orijinal PDF'ini talep edin{$hint}.");
        }

        return ['pages' => [], 'image' => ['path' => $path, 'name' => $pdf->getClientOriginalName() ?: 'rapor.pdf', 'page_count' => $pageCount], 'ocr_pdf' => null, 'input' => 'image'];
    }

    // OCR ikizi (geçici) artık gerekmiyorsa silinir.
    public function forget(?string $ocrPdf): void
    {
        if ($ocrPdf && is_file($ocrPdf)) {
            @unlink($ocrPdf);
        }
    }

    /**
     * ['pages' => metin sayfaları ya da null (metin yok: taranmış / görüntü), 'page_count' => PDF'in sayfa sayısı (pdfium)].
     */
    private function textPages(UploadedFile $pdf): array
    {
        try {
            $pages = $this->extractor->extractPages($pdf);
            if ($this->hasText($pages)) {
                return ['pages' => $pages, 'page_count' => count($pages)];
            }
        } catch (RuntimeException) {
            // smalot metin bulamadı; pdfium denenir.
        }

        $pdfium = $this->pdfiumPages((string) $pdf->getRealPath());
        if ($pdfium === null) {
            return ['pages' => null, 'page_count' => null];
        }
        $pages = array_values(array_filter($pdfium, fn (string $page) => $page !== ''));

        return ['pages' => $this->hasText($pages) ? $pages : null, 'page_count' => count($pdfium) ?: null];
    }

    // Taranmış PDF → dijital ikiz; yapılamazsa null (sebep loglanır, kullanıcı mesajı için $ocrProblem).
    private function ocr(string $path, ?int $pageCount): ?array
    {
        $this->ocrProblem = null;
        if (!config('pktakip.ocr.enabled', true)) {
            return null;
        }
        $maxPages = (int) config('pktakip.ocr.max_pages', 30);
        if ($pageCount !== null && $pageCount > $maxPages) {
            $this->ocrProblem = "{$pageCount} sayfa; OCR ile en fazla {$maxPages} sayfa okunuyor";

            return null;
        }

        $output = storage_path('app/private/pk-ocr-tmp/' . Str::uuid() . '.pdf');
        File::ensureDirectoryExists(dirname($output));
        $env = array_filter(['PK_TESSERACT' => config('pktakip.ocr.tesseract'), 'PK_TESSDATA' => config('pktakip.ocr.tessdata')]);
        $startedAt = microtime(true);
        try {
            $result = Process::timeout((int) config('pktakip.ocr.timeout', 600))
                ->env($env + $this->environment())
                ->run([$this->python(), base_path('scripts/pk_ocr_twin.py'), $path, $output]);
        } catch (\Throwable $exception) {
            Log::warning('pktakip OCR başlatılamadı', ['message' => $exception->getMessage()]);
            $this->forget($output);

            return null;
        }
        $decoded = json_decode($result->output(), true);
        if (!$result->successful() || !is_file($output)) {
            Log::warning('pktakip OCR başarısız', [
                'exit_code' => $result->exitCode(),
                'error' => mb_substr((string) ($decoded['error'] ?? $result->errorOutput()), 0, 500),
            ]);
            $this->forget($output);

            return null;
        }

        $pages = array_values(array_filter((array) $this->pdfiumPages($output), fn (string $page) => $page !== ''));
        Log::info('pktakip OCR tamamlandı', [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'pages' => $decoded['pages'] ?? null,
            'cells' => $decoded['cells'] ?? null,
            'chars' => array_sum(array_map('mb_strlen', $pages)),
        ]);
        if (!$this->hasText($pages)) {
            $this->forget($output);

            return null;
        }

        return ['pages' => $pages, 'pdf' => $output];
    }

    // Tüm sayfalar (boşlar dahil); okunamazsa null.
    private function pdfiumPages(string $path): ?array
    {
        $this->readerBroken = false;
        try {
            $result = Process::timeout(120)->env($this->environment())->run([$this->python(), base_path('scripts/pk_pdf_text.py'), $path]);
        } catch (\Throwable $exception) {
            Log::warning('pktakip pdfium metin okuma başlatılamadı', ['message' => $exception->getMessage()]);
            $this->readerBroken = true;

            return null;
        }
        $decoded = json_decode($result->output(), true);
        if (!$result->successful() || !is_array($decoded['pages'] ?? null)) {
            // Betik hiç çalışamadıysa (paket yok, Python yok) kurulum sorunudur; PDF'in kendi hatası JSON "error" ile gelir.
            $this->readerBroken = !is_array($decoded);
            Log::warning('pktakip pdfium metin okuma başarısız', [
                'exit_code' => $result->exitCode(),
                'error' => mb_substr((string) ($decoded['error'] ?? $result->errorOutput()), 0, 500),
            ]);

            return null;
        }

        return array_map(fn ($page) => trim((string) $page), $decoded['pages']);
    }

    /**
     * Alt işleme sunucu işleminin tüm ortam değişkenleri açıkça verilir. Symfony Process varsayılanda yalnızca
     * $_SERVER'da da bulunanları geçirir; Windows'ta "php artisan serve" altında APPDATA düşer ve Python kullanıcı
     * klasörüne kurulu paketleri (pypdfium2, cv2) bulamaz. Camelot'un proc_open çağrısı zaten tüm ortamı devralıyor.
     */
    private function environment(): array
    {
        return array_filter(getenv(), fn ($value) => is_string($value));
    }

    // Camelot ile aynı Python (sunucuda Camelot, pypdfium2, OpenCV, reportlab kurulu olmalı).
    private function python(): string
    {
        return (string) config('services.camelot.python', env('CAMELOT_PYTHON', 'python'));
    }

    private function hasText(array $pages): bool
    {
        return $pages !== [] && max(array_map('mb_strlen', $pages)) >= self::MIN_PAGE_CHARS;
    }

    private function scannedMessage(string $hint): string
    {
        $reason = $this->ocrProblem ? " ({$this->ocrProblem})" : '';

        return "Bu PDF taranmış ya da görüntü olarak kaydedilmiş; içindeki metin okunamıyor{$reason}. Firmadan raporun orijinal PDF'ini talep edin{$hint}.";
    }
}
