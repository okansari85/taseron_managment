<?php

namespace App\Services\Ai;

/**
 * TÜRKAK-akredite raporların "Genel Bilgiler" ve "Sonuç ve Kanaat"
 * bölümlerini AI'sız (deterministik) ayrıştırır.
 *
 * Gerekçe: iki farklı gerçek rapor (NETA, OKCO) karşılaştırıldığında, bu iki
 * bölümün ETİKETLERİ firmaya göre değişse de (örn. "Muayene Tarihi" ile
 * "Kontrol Tarihi" aynı şeyi ifade ediyor) YAPISI hep aynı: Genel Bilgiler
 * satır bazlı bir etiket→değer tablosu, Sonuç ve Kanaat ise sabit bir
 * "kullan... uygun(değil)" kalıbı taşıyan tek bir cümle. Akreditasyon bu
 * yapıyı zorunlu kıldığı için AI'a hiç gerek yok — sadece bir eşanlamlı
 * sözlüğü (bkz. LABEL_ALIASES) yeterli.
 *
 * ASLA TAHMİN ETMEZ: bir alanı bulamazsa null döner, çağıran taraf
 * (FireSuppressionOptimizedReportParser) o zaman metni her zamanki gibi
 * AI'a düşürmeye devam eder — bu parser'ın başarısız olması hiçbir şeyi
 * bozmaz, sadece bir fırsatı kaçırır.
 */
class FireSuppressionGeneralInfoParser
{
    private const LABEL_ALIASES = [
        'gelecek muayene tarihi' => 'next_control_date',
        'azami geçerlilik tarihi' => 'next_control_date',
        'muayene tarihi ve saati' => 'control_date',
        'muayene tarihi' => 'control_date',
        'kontrol tarihi' => 'control_date',
    ];

    /**
     * @return array{control_date: ?string, next_control_date: ?string, company_name: ?string}
     */
    public function parseGeneralInfo(string $text): array
    {
        $values = $this->extractLabelValues($text);

        return [
            'control_date' => $values['control_date'] ?? null,
            'next_control_date' => $values['next_control_date'] ?? null,
            'company_name' => $this->extractCompanyNameFromFooter($text),
        ];
    }

    // Genel bilgiler bölümü AI'a HİÇ gönderilmeden atlanabilir mi — SADECE
    // iki tarih de (control_date, next_control_date) çözülebildiyse. Firma
    // adı (company_name) en iyi çaba ("best effort") alanıdır, eksik olması
    // AI'a düşmeyi tetiklemez — zaten AI de bu alanda daha önce hataya
    // düşmüştü (bkz. "YT-01" hayalet kaydı olayı, ilgisiz ama aynı kökten).
    public function isGeneralInfoComplete(array $result): bool
    {
        return $result['control_date'] !== null && $result['next_control_date'] !== null;
    }

    public function parseOverallResult(string $text): ?string
    {
        $lower = $this->lowerTr(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (preg_match('/kullan\S*\s+uygun(\s+değil)?/u', $lower, $m)) {
            return (isset($m[1]) && trim($m[1]) !== '') ? 'uygun_degil' : 'uygun';
        }

        return null;
    }

    private function extractLabelValues(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));

        $values = [];

        for ($i = 0; $i < count($lines); $i++) {
            $columns = $this->splitColumns($lines[$i]);
            if (count($columns) < 2) {
                continue;
            }

            // (A) Başlık satırı + hemen altındaki değer satırı (NETA'nın
            // "1. GENEL BİLGİLER" tablosu gibi: "Rapor No  Muayene Tarihi
            // ve Saati  ..." satırının altında "NT/24/...  03.01.2025 ..."
            // geliyor). Yanlışlıkla ilgisiz bir satırı eşleştirmemek için
            // İKİ şart birden aranır: sütun sayısı aynı VE alttaki satırda
            // en az bir hücre tarih kalıbına (gg.aa.yyyy) uyuyor.
            $labelIndexes = [];
            foreach ($columns as $ci => $col) {
                $field = $this->matchLabel($col);
                if ($field !== null) {
                    $labelIndexes[$ci] = $field;
                }
            }

            if ($labelIndexes !== [] && isset($lines[$i + 1])) {
                $valueColumns = $this->splitColumns($lines[$i + 1]);
                $looksLikeValueRow = count($valueColumns) === count($columns)
                    && array_reduce(
                        $valueColumns,
                        fn (bool $carry, string $v) => $carry || (bool) preg_match('/\d{2}\.\d{2}\.\d{4}/u', $v),
                        false
                    );

                if ($looksLikeValueRow) {
                    foreach ($labelIndexes as $ci => $field) {
                        if (isset($valueColumns[$ci]) && ! isset($values[$field])) {
                            $values[$field] = trim($valueColumns[$ci]);
                        }
                    }
                }
            }

            // (B) Aynı satırda etiket→değer çiftleri (OKCO tarzı: "Adresi
            // ...  Kontrol Tarihi  10.04.2026" gibi). SABİT çift-çift
            // (0,1)(2,3)... değil, SIRALI tarama kullanılır — çünkü bazı
            // satırlarda bir etiket İKİ değer taşıyabiliyor (örn. "Başlama
            // ve Bitiş Saati  09:00  12:30"), bu da sabit eşleştirmeyi bir
            // sütun kaydırıp sonraki gerçek etiket-değer çiftini bozuyordu.
            // Sıralı tarama, her etiketi nerede geçerse bulup HEMEN
            // sağındaki hücreyi değeri olarak alır — kayma sorunu olmaz.
            for ($ci = 0; $ci < count($columns) - 1; $ci++) {
                $field = $this->matchLabel($columns[$ci]);
                if ($field !== null && ! isset($values[$field])) {
                    $values[$field] = trim($columns[$ci + 1]);
                    $ci++;
                }
            }
        }

        return $values;
    }

    private function matchLabel(string $column): ?string
    {
        $lower = $this->lowerTr(trim($column));

        foreach (self::LABEL_ALIASES as $needle => $field) {
            if (str_starts_with($lower, $needle)) {
                return $field;
            }
        }

        return null;
    }

    // Akredite kontrol firmasının unvanı raporun "Kontrol Talep Eden
    // Kuruluş" tablosunda DEĞİLDİR — o MÜŞTERİNİN unvanıdır (bkz. gerçek
    // veride görülen "YT-01" hayalet kaydı hatasıyla aynı karışıklık
    // kökeni). Firma adı her sayfanın üst/alt bilgisinde tekrarlanır:
    // "... LTD. ŞTİ" / "... A.Ş." ile biten bir satırı hemen bir "T: 0..."
    // telefon satırı izler — bu yapı NETA ve OKCO'da doğrulandı, raporlar
    // arası tutarlı kabul edilir.
    private function extractCompanyNameFromFooter(string $text): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));

        foreach ($lines as $i => $line) {
            if (! isset($lines[$i + 1])) {
                continue;
            }

            if (preg_match('/(LTD\.\s*ŞTİ\.?|A\.Ş\.?)\s*$/u', $line)
                && preg_match('/^T:\s*\d/u', $lines[$i + 1])) {
                return $line;
            }
        }

        return null;
    }

    private function splitColumns(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $columns = preg_split('/(?:\t+|\s{2,})/u', $value) ?: [];

        return array_values(array_filter(array_map('trim', $columns), fn (string $c) => $c !== ''));
    }

    private function lowerTr(string $value): string
    {
        $value = str_replace(['İ', 'I'], ['i', 'ı'], $value);

        return mb_strtolower($value, 'UTF-8');
    }
}
