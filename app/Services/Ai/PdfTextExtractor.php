<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;

// TEK sorumluluk: belge → ham metin (section 8'deki pipeline'ın "OCR
// çıktısı" aşamasına denk gelir). Bugün metin-tabanlı PDF'ler için
// smalot/pdfparser kullanıyor. Görüntü tabanlı (taranmış) belgeler için OCR
// (örn. Nemotron OCR v2) YARIN buraya eklenecek — Parser ve Matching
// katmanları bundan hiç etkilenmez, ikisi de sadece "raw text" string'i
// görür. OCR sağlayıcısı değişirse (Nemotron → başka bir model) SADECE bu
// sınıf değişir.
class PdfTextExtractor
{
    public function extract(UploadedFile $file): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($file->getRealPath());
        $text = trim($pdf->getText());

        if (mb_strlen($text) < 40) {
            throw new RuntimeException(
                'Bu PDF\'den metin çıkarılamadı (muhtemelen taranmış/görüntü tabanlı bir belge). '
                . 'Görüntü tabanlı PDF\'ler için OCR desteği (Nemotron OCR v2) henüz bu katmana bağlanmadı — lütfen bilgileri elle girin.'
            );
        }

        return $text;
    }

    // Sayfa sayfa metin — çok sayfalı/yoğun raporlarda tüm metni tek bir AI
    // çağrısına vermek yerine sayfa sayfa işlenebilmesi için (bkz.
    // FireSuppressionReportParser/YscReportParser::parse()). NVIDIA NIM'in
    // ücretsiz katmanındaki ağ geçidi, tek seferde çok büyük bir JSON
    // üretilmeye çalışılınca 502/503/504 ile kesebiliyor — küçük parçalar
    // hem daha hızlı döner hem de rapor uzunluğundan bağımsız ölçeklenir.
    public function extractPages(UploadedFile $file): array
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($file->getRealPath());
        $pages = array_values(array_filter(array_map(
            fn ($page) => trim($page->getText()),
            $pdf->getPages()
        ), fn (string $text) => $text !== ''));

        $combinedLength = array_sum(array_map('mb_strlen', $pages));

        if ($combinedLength < 40) {
            throw new RuntimeException(
                'Bu PDF\'den metin çıkarılamadı (muhtemelen taranmış/görüntü tabanlı bir belge). '
                . 'Görüntü tabanlı PDF\'ler için OCR desteği (Nemotron OCR v2) henüz bu katmana bağlanmadı — lütfen bilgileri elle girin.'
            );
        }

        return $pages;
    }
}
