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
            if (preg_match('/^(?:[A-Z]{1,3}\s+)?(?:Dolap|Hidrant)\s+No\s+(.+)$/iu', $line, $m)) {
                $numbers = $this->splitEquipmentNumbers($m[1]);
                continue;
            }

            if (preg_match('/^(?:[A-Z]{1,3}\s+)?Bulunduğu\s+Yer\s+(.+)$/iu', $line, $m)) {
                $locations = $this->splitColumns($m[1]);
                continue;
            }

            if (preg_match('/^(?:[A-Z]{1,3}\s+)?Ölçülen\s+Basınç\s+(.+)$/iu', $line, $m)) {
                $pressures = $this->splitColumns($m[1]);
                continue;
            }

            if (preg_match('/^(?:[A-Z]{1,3}\s+)?(?:Hortum\s+Uzunluğu|Korunan\s+Alandan\s+Uzaklığı|Hidrantlar\s+Arası\s+Max\.\s+Mesafe)\s+(.+)$/iu', $line, $m)) {
                $measurements[] = $this->splitColumns($m[1]);
            }

            if ($numbers !== [] && $locations !== []) {
                $records = array_merge(
                    $records,
                    $this->buildRecords($equipmentType, $numbers, $locations, $pressures, $measurements)
                );

                $numbers = [];
                $locations = [];
                $pressures = [];
                $measurements = [];
            }
        }

        return $this->deduplicate($records);
    }

    private function detectType(string $text): ?string
    {
        $lower = mb_strtolower($text, 'UTF-8');

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
