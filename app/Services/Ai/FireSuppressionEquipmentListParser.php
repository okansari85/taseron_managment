<?php

namespace App\Services\Ai;

/**
 * Conservative parser for repeating equipment-list tables.
 *
 * It emits records only when an equipment number and its corresponding
 * location can be aligned without guessing. If the PDF text extractor loses
 * column boundaries, it returns an empty result and the existing AI parser
 * remains the fallback.
 */
class FireSuppressionEquipmentListParser
{
    public function parse(string $pageText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $pageText) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $line) => $line !== ''));

        $equipmentType = $this->detectType($pageText);
        if ($equipmentType === null) {
            return [];
        }

        $numbers = [];
        $locationLines = [];
        $collectingLocation = false;
        $pressures = [];
        $measurements = [];
        $records = [];

        foreach ($lines as $line) {
            $lowerLine = $this->lowerTr($line);

            if (($value = $this->valueAfterKeyword($line, $lowerLine, ['dolap no', 'hidrant no'])) !== null) {
                // Yeni bir "No" satırı, ÖNCEKİ bloğun bittiğini gösterir
                // (bir sayfada aynı liste birkaç 5'li sütun grubuna
                // bölünmüş halde tekrar tekrar "Soru / Kriter" başlığıyla
                // devam ediyor — bunların HEPSİ aynı listenin parçası,
                // her "No" satırında önceki blok kapatılıp kaydediliyor).
                if ($numbers !== []) {
                    $locations = $this->splitLocations(implode(' ', $locationLines), count($numbers));
                    $records = array_merge(
                        $records,
                        $this->buildRecords($equipmentType, $numbers, $locations, $pressures, $measurements)
                    );
                }

                $numbers = $this->splitEquipmentNumbers($value);
                $locationLines = [];
                $collectingLocation = false;
                $pressures = [];
                $measurements = [];
                continue;
            }

            if (($value = $this->valueAfterKeyword($line, $lowerLine, ['bulunduğu yer'])) !== null) {
                // "Bulunduğu Yer" değeri gerçek raporlarda TEK satıra sığmıyor:
                // bazen etiketin hemen yanında (aynı satırda), bazen etiketten
                // SONRAKİ satırlarda serbest metin olarak sarmalanmış halde
                // geliyor (satır sonu, sütun sınırı değil). Bu yüzden burada
                // sadece bu satırdaki değeri almakla kalmıyoruz — bir sonraki
                // bilinen anahtar kelimeye kadar gelen TÜM satırları
                // toplayıp (aşağıdaki $collectingLocation bloğu) sona kadar
                // biriktiriyoruz; asıl ayrıştırma splitLocations()'da olur.
                $locationLines = $value !== '' ? [$value] : [];
                $collectingLocation = true;
                continue;
            }

            if (($value = $this->valueAfterKeyword($line, $lowerLine, ['ölçülen basınç'])) !== null) {
                $collectingLocation = false;
                $pressures = $this->splitColumns($value);
                continue;
            }

            if (($value = $this->valueAfterKeyword($line, $lowerLine, [
                'hortum uzunluğu',
                'korunan alandan uzaklığı',
                'hidrantlar arası max. mesafe',
                'hidrantlar arası max mesafe',
                'dolaplar arası mesafe',
                'hidrantlar arası mesafe',
            ])) !== null) {
                $collectingLocation = false;
                $measurements[] = $this->splitColumns($value);
                continue;
            }

            if ($collectingLocation) {
                $locationLines[] = $line;
            }
        }

        // Tablo sayfanın SON içeriğiyse döngü bir sonraki "No" satırına hiç
        // ulaşmadan biter — döngü sonunda kalan bir blok varsa burada işlenir.
        if ($numbers !== []) {
            $locations = $this->splitLocations(implode(' ', $locationLines), count($numbers));
            $records = array_merge(
                $records,
                $this->buildRecords($equipmentType, $numbers, $locations, $pressures, $measurements)
            );
        }

        return $this->deduplicate($records);
    }

    private function detectType(string $text): ?string
    {
        $lower = $this->lowerTr($text);

        if (str_contains($lower, 'yangın dolabı listesi') || str_contains($lower, 'dolap no')) {
            return 'yangin_dolabi';
        }

        if (str_contains($lower, 'hidrant listesi') || str_contains($lower, 'hidrant no')) {
            return 'hidrant';
        }

        if (str_contains($lower, 'sprinkler listesi')) {
            return 'sprinkler';
        }

        return null;
    }

    // bkz. PdfPageClassifier::lowerTr() — aynı Türkçe İ/I küçültme hatası
    // burada da vardı: "HİDRANT NO", "YANGIN DOLABI LİSTESİ" gibi büyük
    // harfli başlıklar düz mb_strtolower ile anahtar kelimeyle eşleşmiyor,
    // detectType() null dönüyor, tüm liste gereksiz yere AI'a düşüyordu.
    private function lowerTr(string $value): string
    {
        $value = str_replace(['İ', 'I'], ['i', 'ı'], $value);

        return mb_strtolower($value, 'UTF-8');
    }

    // Eski kod PCRE'nin /i bayrağına güvenerek orijinal (küçültülmemiş)
    // satırda "Dolap|Hidrant\s+No" gibi karışık büyük/küçük harfli bir
    // desen arıyordu — ama PCRE'nin case-insensitive eşleştirmesi de
    // Türkçe İ/I için güvenilir değil (yukarıdaki lowerTr() ile aynı
    // sınıf hata, sadece regex seviyesinde). Bunun yerine: anahtar kelime
    // ÖNCE küçültülmüş satırda (satır başına yakın, en fazla 5 karakter
    // içeride — "E " gibi kısa bir sütun önekine izin vermek için) aranır,
    // bulunursa DEĞER orijinal (büyük/küçük harfi korunmuş) satırdan aynı
    // karakter ofsetinden itibaren alınır (lowerTr karakter sayısını
    // değiştirmez, sadece değerleri).
    private function valueAfterKeyword(string $originalLine, string $lowerLine, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            $pos = mb_strpos($lowerLine, $keyword);

            if ($pos !== false && $pos <= 5) {
                return trim(mb_substr($originalLine, $pos + mb_strlen($keyword)));
            }
        }

        return null;
    }

    private function splitEquipmentNumbers(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        // Equipment identifiers are short and do not contain spaces.
        $tokens = preg_split('/\s+/u', $value) ?: [];

        return array_values(array_filter($tokens, function (string $token): bool {
            return (bool) preg_match('/^(?:[A-ZÇĞİÖŞÜ]{0,3}\s*)?\d+(?:[-\/]\d+)*$/iu', $token);
        }));
    }

    private function splitColumns(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        // Two or more spaces / tabs are the only safe column boundary for
        // free-text locations. A single-space fallback would split a place
        // name such as "Zemin Kat Koridor" incorrectly, so we deliberately
        // refuse to guess when the extractor flattened the columns.
        $columns = preg_split('/(?:\t+|\s{2,})/u', $value) ?: [];
        $columns = array_values(array_filter(array_map('trim', $columns), fn (string $item) => $item !== ''));

        return count($columns) >= 1 ? $columns : [];
    }

    // Gerçek raporlarda "Bulunduğu Yer" hücresi tek satıra sığmıyor — etiket
    // bazen kendi satırında yalnız duruyor, değer sonraki serbest metin
    // satırlarına SARIYOR (bazen tire ile bölünmüş), ve komşu iki hücre
    // ARADA HİÇ BOŞLUK OLMADAN birbirine yapışabiliyor (örn.
    // "...A-20İDARİ BİNA BÜRO ÜST A-18"). splitColumns() (tek satır, tab/çift
    // boşluk sınırlı) bu durumların HİÇBİRİNİ çözemez. Burada önce onu
    // deniyoruz (aynı satırdaysa yeterli), olmazsa raporun KENDİ tekrar eden
    // yapısından yararlanıyoruz: her konum değeri aynı bina/tesis adıyla
    // başlıyor (örn. "İDARİ BİNA", "SOLAR BİNA") — metnin ilk iki kelimesini
    // bu tekrar eden önek olarak alıp, önek NEREDE geçerse (boşluk olsun
    // olmasın) yeni bir değerin başladığını kabul ediyoruz. Bu, extractor'ın
    // kaybettiği sütun sınırını verinin kendi tekrarından geri kazanıyor ve
    // boşluksuz yapışma durumunu da otomatik çözüyor (lookahead split boşluğa
    // bakmaz). Hiçbiri $expectedCount'a denk gelmezse konum null bırakılır —
    // kod/basınç/ölçüm verisi yine de KAYBOLMAZ (bkz. buildRecords).
    private function splitLocations(string $text, int $expectedCount): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($expectedCount <= 0) {
            return [];
        }

        if ($text === '') {
            return array_fill(0, $expectedCount, null);
        }

        if ($expectedCount === 1) {
            return [$text];
        }

        $columns = $this->splitColumns($text);
        if (count($columns) === $expectedCount) {
            return $columns;
        }

        $words = preg_split('/\s+/u', $text) ?: [];
        if (count($words) >= 2) {
            $prefix = $words[0] . ' ' . $words[1];
            $parts = preg_split('/(?=' . preg_quote($prefix, '/') . ')/u', $text) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts), fn (string $p) => $p !== ''));

            if (count($parts) === $expectedCount) {
                return $parts;
            }
        }

        return array_fill(0, $expectedCount, null);
    }

    private function buildRecords(string $type, array $numbers, array $locations, array $pressures, array $measurements): array
    {
        $records = [];

        foreach ($numbers as $i => $number) {
            $number = trim($number);
            if ($number === '') {
                continue;
            }

            $location = $locations[$i] ?? null;
            $location = $location !== null ? trim((string) $location) : null;
            if ($location === '') {
                $location = null;
            }

            $record = [
                'code' => $number,
                'category' => $type,
                'location_note' => $location,
                'brand' => null,
                'model' => null,
                'serial_no' => null,
                'result' => null,
                'note' => null,
                'control_items' => [],
                'is_uncertain' => $location === null,
            ];

            if (isset($pressures[$i])) {
                $record['measured_pressure'] = $pressures[$i];
            }

            foreach ($measurements as $measurementIndex => $values) {
                if (isset($values[$i])) {
                    $record['measurement_' . ($measurementIndex + 1)] = $values[$i];
                }
            }

            $records[] = $record;
        }

        return $records;
    }

    private function deduplicate(array $records): array
    {
        $seen = [];
        $result = [];

        foreach ($records as $record) {
            $key = ($record['category'] ?? '') . '|' . ($record['code'] ?? '') . '|' . ($record['location_note'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $record;
        }

        return $result;
    }
}
