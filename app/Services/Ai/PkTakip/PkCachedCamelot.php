<?php

namespace App\Services\Ai\PkTakip;

use App\Services\Ai\CamelotPdfTableExtractor;
use Illuminate\Support\Str;

/**
 * PKTakip okuyucularına özel Camelot: aynı PDF bir işlemde bir kez okunur. Tablo normalizer'ı Camelot'u birkaç yerden
 * çağırıyor; tesisat okumasında tablo etiketleri (PkReportText) de aynı sonucu kullanır. Taşeron'un Camelot kullanımı
 * değişmez (bağlama yalnız PkTakipServiceProvider'daki PKTakip sınıflarına).
 */
class PkCachedCamelot extends CamelotPdfTableExtractor
{
    // Uzun yaşayan kuyruk işçisinde büyümesin: son birkaç PDF.
    private const KEEP = 4;

    private static array $results = [];

    public function extract(string $pdfPath): array
    {
        $key = $pdfPath . '|' . (is_file($pdfPath) ? filemtime($pdfPath) . '|' . filesize($pdfPath) : '');
        if (!array_key_exists($key, self::$results)) {
            self::$results[$key] = parent::extract($pdfPath);
            self::$results = array_slice(self::$results, -self::KEEP, null, true);
        }

        return self::$results[$key];
    }

    /**
     * Tablo şablonu alt ajanı için Camelot'un gördüğü etiketler (başlık satırları ve ilk sütunlar): şema seçenek listesi,
     * böylece şablondaki başlıklar Camelot'un hücreleriyle birebir eşleşir.
     *
     * @return list<string>
     */
    public function labels(string $pdfPath, int $limit = 800): array
    {
        $labels = [];
        foreach ((array) ($this->extract($pdfPath)['tables'] ?? []) as $table) {
            $rows = array_values(array_filter((array) ($table['data'] ?? []), 'is_array'));
            $cells = [...array_merge(...array_map('array_values', array_slice($rows, 0, 2)) ?: [[]]), ...array_map(fn (array $row) => $row[0] ?? '', $rows), ...array_map(fn (array $row) => $row[1] ?? '', $rows)];
            foreach ($cells as $cell) {
                $cell = trim((string) $cell);
                // Etiket adayı: harf içeren kısa hücre (rakam / tarih / uzun cümle değerleri etiket değildir).
                if ($cell !== '' && mb_strlen($cell) <= 60 && preg_match('/\p{L}/u', $cell) && !preg_match('/^[\d\s.,:\/-]+$/u', $cell)) {
                    $labels[$cell] = true;
                }
            }
        }

        return array_slice(array_keys($labels), 0, $limit);
    }
}
