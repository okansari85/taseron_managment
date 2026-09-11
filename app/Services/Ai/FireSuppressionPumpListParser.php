<?php

namespace App\Services\Ai;

/**
 * Pompa Dairesi için deterministik (AI'sız) parser.
 *
 * Dolap/Hidrant'tan farklı olarak pompa tablosu tek bir sabit formatta
 * gelmiyor — iki gerçek varyant gözlemlendi:
 *
 *  (A) "5.1. 1 NUMARALI POMPA" tarzı, HER POMPA KENDİ ALT BAŞLIĞINDA, altında
 *      "Etiket\tDeğer\tEtiket2\tDeğer2" satırları (NETA formatı).
 *  (B) "Pompa No  1  2  3  Jokey" tarzı, TEK tablo, satır=alan/sütun=pompa,
 *      hücreler ya düz değer ("MAS") ya da "P: <proje> U: <uygulama>" çifti
 *      (OKCO formatı — P=proje değeri, U=uygulamadaki gerçek değer).
 *
 * Çıktı SADECE açıklayıcı metadata (marka/model/seri no/teknik değerler) —
 * dolap/hidrant'taki gibi bir U/UD madde matrisi ÜRETMEZ; pompa dairesinin
 * kontrol sonuçları (5.4, 5.5, ... UD/U) ayrı bir "whole_unit" checklist
 * bölümünden (control_criteria) gelir, bu parser'ın işi değildir.
 *
 * Hiçbir format tanınmazsa [] döner — çağıran taraf (bkz.
 * FireSuppressionOptimizedReportParser) bunu her zamanki gibi AI'a düşürür.
 */
class FireSuppressionPumpListParser
{
    private const LABEL_ALIASES = [
        'marka' => 'brand',
        'tip - model' => 'model',
        'tip-model' => 'model',
        'tip / model' => 'model',
        'seri no' => 'serial_no',
        'pompa seri no' => 'serial_no',
        'debi' => 'measurement_1',
        'basınç' => 'measurement_2',
        'güç' => 'measurement_3',
        'yakıt' => 'measurement_4',
        'tür' => 'measurement_5',
    ];

    public function parse(string $pageText): array
    {
        $records = $this->parsePerPumpSubsections($pageText);

        return $records !== [] ? $records : $this->parseTransposedTable($pageText);
    }

    // ------------------------------------------------------------------
    // (A) "5.1. 1 NUMARALI POMPA" — her pompa kendi alt başlığında.
    // ------------------------------------------------------------------
    private function parsePerPumpSubsections(string $pageText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $pageText) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));

        $records = [];
        $current = null;

        $flush = function () use (&$records, &$current): void {
            if ($current !== null) {
                $records[] = $current;
            }
        };

        foreach ($lines as $line) {
            // "N.N. text" kalıbı bölümün KENDİ üst başlığıyla da eşleşir
            // (örn. "2.1. YANGIN POMPALARI TESPİT VE DEĞERLENDİRMELER") —
            // bunu yanlışlıkla bir pompa alt-başlığı saymamak için, başlık
            // metni SADECE "N NUMARALI POMPA" veya "JOKEY POMPA" kalıbına
            // uyuyorsa kabul edilir (gerçek pompa alt-başlıkları hep bu
            // şekilde, genel bölüm başlığı asla değil).
            if (preg_match('/^\d+\.\d+\.\s*(.+)$/u', $line, $m)
                && preg_match('/^(\d+\s+numaral[ıi]\s+pompa|jokey\s+pompa)$/u', $this->lowerTr(trim($m[1])))) {
                $flush();
                $current = $this->emptyPumpRecord(trim($m[1]));
                continue;
            }

            if ($current === null) {
                continue;
            }

            $columns = $this->splitTabColumns($line);
            for ($i = 0; $i + 1 < count($columns); $i += 2) {
                $this->applyLabelValue($current, $columns[$i], $columns[$i + 1]);
            }
        }

        $flush();

        return $this->dropAllEmpty($records);
    }

    // ------------------------------------------------------------------
    // (B) "Pompa No  1  2  3  Jokey" — tek transpoze tablo.
    // ------------------------------------------------------------------
    private function parseTransposedTable(string $pageText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $pageText) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));

        $labels = [];
        $rows = []; // label anahtarı => [sütun index => değer]

        foreach ($lines as $line) {
            // Satırın TAMAMI önce sütunlara bölünür (label dahil) — sadece
            // alias kelimesinin uzunluğu kadar kırpmak YANLIŞ: gerçek
            // etiketler parantezli birim taşıyabiliyor ("Güç (kw)"), bu da
            // sabit-uzunluk kırpmada değer sütunlarını bir kaydırıyordu.
            $columns = $this->splitTabColumns($line);
            if ($columns === []) {
                continue;
            }

            $firstColumnLower = $this->lowerTr($columns[0]);

            if (str_starts_with($firstColumnLower, 'pompa no')) {
                $labels = array_slice($columns, 1);
                continue;
            }

            if ($labels === []) {
                continue;
            }

            foreach (self::LABEL_ALIASES as $needle => $field) {
                if (str_starts_with($firstColumnLower, $needle)) {
                    $rows[$field] = array_slice($columns, 1);

                    continue 2;
                }
            }
        }

        if ($labels === []) {
            return [];
        }

        $records = [];

        foreach ($labels as $index => $label) {
            $record = $this->emptyPumpRecord($this->pumpDisplayName($label));

            foreach ($rows as $field => $values) {
                if (! isset($values[$index])) {
                    continue;
                }

                $record[$field] = $this->extractActualValue($values[$index]);
            }

            $records[] = $record;
        }

        return $this->dropAllEmpty($records);
    }

    // "P: <proje> U: <uygulama>" hücresinden UYGULAMADAKİ (gerçek, kurulu)
    // değeri alır — proje/tasarım değeri değil, sahadaki gerçek durumu
    // yansıtan budur. Çift yoksa değer aynen döner.
    private function extractActualValue(string $cell): string
    {
        if (preg_match('/U:\s*(.*)$/u', $cell, $m)) {
            return trim($m[1]);
        }

        return trim($cell);
    }

    private function applyLabelValue(array &$record, string $label, string $value): void
    {
        $lowerLabel = $this->lowerTr($label);

        foreach (self::LABEL_ALIASES as $needle => $field) {
            if (str_starts_with($lowerLabel, $needle)) {
                $record[$field] = $this->extractActualValue(trim($value));

                return;
            }
        }
    }

    private function pumpDisplayName(string $columnHeader): string
    {
        $lower = $this->lowerTr(trim($columnHeader));

        if ($lower === 'jokey') {
            return 'Jokey Pompa';
        }

        return trim($columnHeader) . ' Numaralı Pompa';
    }

    private function emptyPumpRecord(string $name): array
    {
        return [
            'code' => $name,
            'category' => 'yangin_pompasi',
            'location_note' => null,
            'brand' => null,
            'model' => null,
            'serial_no' => null,
            'result' => null,
            'note' => null,
            'control_items' => [],
            'is_uncertain' => false,
            'measurement_1' => null,
            'measurement_2' => null,
            'measurement_3' => null,
            'measurement_4' => null,
            'measurement_5' => null,
        ];
    }

    // Bir pompa sütunundaki TÜM alanlar "-"/boş ise o pompa fiilen kurulu
    // değildir (mevcut AI prompt'unun da uyguladığı kural) — kayıt üretmenin
    // anlamı yok.
    private function dropAllEmpty(array $records): array
    {
        return array_values(array_filter($records, function (array $record): bool {
            foreach (['brand', 'model', 'serial_no', 'measurement_1', 'measurement_2', 'measurement_3', 'measurement_4', 'measurement_5'] as $field) {
                $value = $record[$field] ?? null;
                if ($value !== null && $value !== '' && $value !== '-') {
                    return true;
                }
            }

            return false;
        }));
    }

    private function splitTabColumns(string $value): array
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
