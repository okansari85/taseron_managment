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
        $locations = [];
        $pressures = [];
        $measurements = [];
        $records = [];

        foreach ($lines as $line) {
            $lowerLine = $this->lowerTr($line);

            if (($value = $this->valueAfterKeyword($line, $lowerLine, ['dolap no', 'hidrant no'])) !== null) {
                // Yeni bir "No" satırı, ÖNCEKİ bloğun bittiğini gösterir
                // (bir sayfada birden fazla tablo olabilir) — henüz
                // kaydedilmediyse önce onu işleyip sıfırlıyoruz, SONRA yeni
                // bloğa başlıyoruz. NOT: eski kod her eşleşmeden sonra
                // "continue" ile bu kontrolü sadece "eşleşmeyen" satırlara
                // bırakıyordu — tablo sayfanın SON içeriğiyse hiç
                // çalışmıyor, kayıtlar sessizce kayboluyordu (aşağıdaki
                // döngü-sonu kontrolü bunu kapatıyor).
                if ($numbers !== [] && $locations !== []) {
                    $records = array_merge(
                        $records,
                        $this->buildRecords($equipmentType, $numbers, $locations, $pressures, $measurements)
                    );
                }

                $numbers = $this->splitEquipmentNumbers($value);
                $locations = [];
                $pressures = [];
                $measurements = [];
                continue;
            }

            if (($value = $this->valueAfterKeyword($line, $lowerLine, ['bulunduğu yer'])) !== null) {
                $locations = $this->splitColumns($value);
                continue;
            }

            if (($value = $this->valueAfterKeyword($line, $lowerLine, ['ölçülen basınç'])) !== null) {
                $pressures = $this->splitColumns($value);
                continue;
            }

            if (($value = $this->valueAfterKeyword($line, $lowerLine, ['hortum uzunluğu', 'korunan alandan uzaklığı', 'hidrantlar arası max. mesafe', 'hidrantlar arası max mesafe'])) !== null) {
                $measurements[] = $this->splitColumns($value);
            }
        }

        // Tablo sayfanın SON içeriğiyse döngü bir sonraki "No" satırına hiç
        // ulaşmadan biter — döngü sonunda kalan bir blok varsa burada işlenir.
        if ($numbers !== [] && $locations !== []) {
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

    private function buildRecords(string $type, array $numbers, array $locations, array $pressures, array $measurements): array
    {
        if (count($numbers) !== count($locations)) {
            return [];
        }

        $records = [];

        foreach ($numbers as $i => $number) {
            $location = trim((string) ($locations[$i] ?? ''));
            if ($number === '' || $location === '') {
                continue;
            }

            $record = [
                'code' => trim($number),
                'category' => $type,
                'location_note' => $location,
                'brand' => null,
                'model' => null,
                'serial_no' => null,
                'result' => null,
                'note' => null,
                'control_items' => [],
                'is_uncertain' => false,
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
