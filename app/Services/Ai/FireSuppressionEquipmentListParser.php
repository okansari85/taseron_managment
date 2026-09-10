<?php

namespace App\Services\Ai;

/**
 * Extracts the simple repeating equipment tables found in fire suppression
 * inspection reports. It is intentionally conservative and returns only
 * records with a clear equipment number and location/measurement context.
 *
 * Supported first-pass shapes:
 * - Yangın Dolabı Listesi / Dolap No + Bulunduğu Yer
 * - Hidrant Listesi / Hidrant No + Bulunduğu Yer
 * - Sprinkler Listesi
 *
 * This parser does not decide database identity or perform matching.
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

        $records = [];
        $currentNumbers = [];
        $currentLocations = [];
        $currentPressures = [];
        $currentMeasurements = [];

        foreach ($lines as $line) {
            if (preg_match('/^(?:[A-Z]{1,3}\s+)?(?:Dolap|Hidrant)\s+No\s+(.+)$/iu', $line, $m)) {
                $currentNumbers = $this->splitValues($m[1]);
                $currentLocations = [];
                $currentPressures = [];
                $currentMeasurements = [];
                continue;
            }

            if (preg_match('/^Soru\s*\/\s*Kriter\s+/iu', $line)) {
                continue;
            }

            if (preg_match('/^(?:[A-Z]{1,3}\s+)?(?:Bulunduğu\s+Yer)\s+(.+)$/iu', $line, $m)) {
                $currentLocations = $this->splitValues($m[1]);
                continue;
            }

            if (preg_match('/^(?:[A-Z]{1,3}\s+)?(?:Ölçülen\s+Basınç)\s+(.+)$/iu', $line, $m)) {
                $currentPressures = $this->splitValues($m[1]);
                continue;
            }

            if (preg_match('/^(?:[A-Z]{1,3}\s+)?(?:Hortum\s+Uzunluğu|Korunan\s+Alandan\s+Uzaklığı|Hidrantlar\s+Arası\s+Max\.\s+Mesafe)\s+(.+)$/iu', $line, $m)) {
                $currentMeasurements[] = $this->splitValues($m[1]);
            }

            if ($currentNumbers !== [] && $currentLocations !== []) {
                $records = array_merge(
                    $records,
                    $this->buildRecords($equipmentType, $currentNumbers, $currentLocations, $currentPressures, $currentMeasurements)
                );
                $currentNumbers = [];
                $currentLocations = [];
                $currentPressures = [];
                $currentMeasurements = [];
            }
        }

        // Some PDF text extractors place the label/value on adjacent lines.
        // The first-pass parser above intentionally avoids guessing across
        // arbitrary lines; only complete number+location groups are emitted.
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

    private function splitValues(string $value): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return [];
        }

        // Keep compound equipment numbers (e.g. 48-49-50) intact for the
        // caller; splitting those into identities requires a separate rule.
        return preg_split('/\s{2,}|\s+(?=\d+(?:-\d+)+$)/u', $value) ?: [$value];
    }

    private function buildRecords(string $type, array $numbers, array $locations, array $pressures, array $measurements): array
    {
        $records = [];
        $count = min(count($numbers), count($locations));

        for ($i = 0; $i < $count; $i++) {
            $number = trim((string) $numbers[$i]);
            $location = trim((string) $locations[$i]);

            if ($number === '' || $location === '') {
                continue;
            }

            $record = [
                'category' => $type,
                'source_code' => $number,
                'location_note' => $location,
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
            $key = ($record['category'] ?? '') . '|' . ($record['source_code'] ?? '') . '|' . ($record['location_note'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $record;
        }

        return $result;
    }
}
