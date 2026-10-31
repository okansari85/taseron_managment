<?php

namespace App\Services\Ai\PkTakip;

/**
 * Tablo okuyucunun okuyamadığı ölçüm listesi: başlık bir tablonun ortasında, satırlar başlıksız ve sütun sayısı farklı devam tablolarında
 * (topraklama: başlık 13 sütunlu kriter tablosunda, devamı 10 sütun). Hücre, başlığa satır numarasıyla değil Camelot'un sütun konumuyla
 * bağlanır: devam tablosunun sütun kenarları başlık tablosununkilerin alt kümesidir. Yapay zeka ve metin ayıklama yok.
 */
class PkGridRows
{
    private const EDGE = 1.5; // PDF noktası: aynı kenar sayılan fark

    /**
     * @param list<array> $tables Camelot çizgili tabloları (data + cells), rapor sırasıyla
     * @param list<string> $fields özellik başlıkları; ilki ölçüm noktasının adı
     * @return list<array{code: string, properties: array<string, string>, page: int}>
     */
    public static function rows(array $tables, string $identity, array $fields): array
    {
        foreach ($tables as $t => $table) {
            foreach ((array) ($table['data'] ?? []) as $r => $row) {
                if (in_array($identity, $row, true)) {
                    return self::read($tables, $t, $r, $identity, $fields);
                }
            }
        }

        return [];
    }

    private static function read(array $tables, int $t, int $r, string $identity, array $fields): array
    {
        $head = $tables[$t];
        $top = $head['data'][$r];
        $sub = (array) ($head['data'][$r + 1] ?? []);
        $hasSub = (bool) array_intersect($sub, $fields);
        $columns = [];
        $label = '';
        foreach ($top as $c => $cell) {
            // Birleşik başlık hücresi sağdaki boş sütunları da kapsar; alt başlık varsa o geçerlidir.
            $label = ($hasSub ? (string) ($sub[$c] ?? '') : '') ?: ((string) $cell ?: $label);
            $columns[] = ['x1' => (float) $head['cells'][$r][$c]['x1'], 'x2' => (float) $head['cells'][$r][$c]['x2'], 'label' => $label];
        }
        $edges = array_column($columns, 'x1');
        $first = $fields[0] ?? '';

        $out = [];
        for ($i = $t; $i < count($tables); $i++) {
            $table = $tables[$i];
            if ($i > $t && !self::continues($table, $edges)) {
                break;
            }
            foreach ((array) ($table['data'] ?? []) as $k => $row) {
                if ($i === $t && $k <= $r + ($hasSub ? 1 : 0)) {
                    continue;
                }
                // Aynı kimlikle yeni başlık: sütunlar değişir, kalan satırlar yeni başlığa göre okunur (Neta topraklama: önce Rx, sonra Zx sütunları).
                if (($i > $t || $k > $r) && in_array($identity, array_map(fn ($cell) => trim((string) $cell), $row), true)) {
                    return [...$out, ...self::read($tables, $i, $k, $identity, array_values(array_filter($row, fn ($cell) => trim((string) $cell) !== '' && trim((string) $cell) !== $identity)) ?: $fields)];
                }
                $values = [];
                foreach ($row as $c => $cell) {
                    if (trim((string) $cell) === '' || !isset($table['cells'][$k][$c])) {
                        continue;
                    }
                    $center = ((float) $table['cells'][$k][$c]['x1'] + (float) $table['cells'][$k][$c]['x2']) / 2;
                    foreach ($columns as $column) {
                        if ($center >= $column['x1'] && $center <= $column['x2']) {
                            $values[$column['label']] = trim(($values[$column['label']] ?? '') . ' ' . trim((string) $cell));
                            break;
                        }
                    }
                }
                // Sıra no ile ad bazı satırlarda aynı hücrede ("5 Kombinasyon"), bazılarında ayrı ("3" + "Priz"): birlikte kimliktir.
                $code = trim(($values[$identity] ?? '') . ' ' . ($values[$first] ?? ''));
                unset($values[$identity], $values[$first]);
                // Değeri olmayan satır ölçüm değildir (96320-topraklama: sayfa sonunda tek başına "Not 6").
                if ($code !== '' && $values) {
                    $out[] = ['code' => $code, 'properties' => $values, 'page' => (int) ($table['page'] ?? 0)];
                }
            }
        }

        return $out;
    }

    // Devam tablosu: bütün sütun kenarları başlık tablosunda da var. Tek sütunlu yazı tablosu devam değildir.
    private static function continues(array $table, array $edges): bool
    {
        $mine = array_map(fn (array $cell) => (float) $cell['x1'], (array) ($table['cells'][0] ?? []));
        if (count($mine) < 2) {
            return false;
        }
        foreach ($mine as $edge) {
            if (!array_filter($edges, fn (float $other) => abs($other - $edge) <= self::EDGE)) {
                return false;
            }
        }

        return true;
    }
}
