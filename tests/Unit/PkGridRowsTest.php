<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkGridRows;
use PHPUnit\Framework\TestCase;

class PkGridRowsTest extends TestCase
{
    private function table(array $edges, array $rows, int $page = 1): array
    {
        $cells = array_map(fn () => array_map(fn (int $i) => ['x1' => $edges[$i], 'x2' => $edges[$i + 1]], array_keys(array_slice($edges, 1))), $rows);

        return ['page' => $page, 'data' => $rows, 'cells' => $cells];
    }

    // 378300-topraklama: başlık kriter satırlarının altında, alt başlıklı; devam tablosunda sütun birleşik, sıra no bazen adla aynı hücrede.
    public function test_rows_follow_column_position_across_tables_with_different_column_counts(): void
    {
        $tables = [
            $this->table([7, 28, 157, 200, 220, 263, 332, 559], [
                ['KONTROL', '', '', '', '', '', ''],
                ['Sıra', 'Ölçüm Noktası', 'Koruma', '', '', 'Ölçülen', 'Not'],
                ['', '', 'In (A)', '', 'Eğri', '', ''],
                ['', '1 Ana Kesici', '40', '', 'C', '0,55', 'Not 1'],
            ]),
            $this->table([7, 28, 157, 220, 263, 332, 559], [
                ['3', 'Priz', '16', 'B', '0,93', 'Not 6'],
                ['', '5 Kombinasyon', '25', 'C', '', 'Not 2'],
                ['Not 6', '', '', '', '', ''],
            ], 2),
            $this->table([7, 559], [['8. SONUÇ']], 3),
            $this->table([7, 28, 157, 220, 263, 332, 559], [['9', 'Sonuç sonrası', '1', '2', '3', '4']], 3),
        ];

        $rows = PkGridRows::rows($tables, 'Sıra', ['Ölçüm Noktası', 'In (A)', 'Eğri', 'Ölçülen', 'Not']);

        $this->assertSame(['1 Ana Kesici', '3 Priz', '5 Kombinasyon'], array_column($rows, 'code'));
        $this->assertSame(['In (A)' => '40', 'Eğri' => 'C', 'Ölçülen' => '0,55', 'Not' => 'Not 1'], $rows[0]['properties']);
        $this->assertSame(['In (A)' => '16', 'Eğri' => 'B', 'Ölçülen' => '0,93', 'Not' => 'Not 6'], $rows[1]['properties']);
        // Boş hücre sonraki sütuna kaymaz.
        $this->assertSame(['In (A)' => '25', 'Eğri' => 'C', 'Not' => 'Not 2'], $rows[2]['properties']);
        $this->assertSame(2, $rows[1]['page']);
    }

    // Neta topraklama: devam sayfası başlıksız; son tabloda aynı kimlikle yeni sütunlar (Rx yerine Zx).
    public function test_new_header_with_same_identity_switches_columns(): void
    {
        $edges = [10, 40, 200, 300, 400];
        $rows = PkGridRows::rows([
            $this->table($edges, [['Sıra No', 'Ölçüm Yeri', 'Rx', 'Sonuç'], ['1', 'Priz 1', '0,7', 'U']]),
            $this->table($edges, [['2', 'Priz 2', '0,8', 'U']], 2),
            $this->table($edges, [['Sıra No', 'Ölçüm Yeri', 'Zx', 'Sonuç'], ['3', 'Pano', '0,9', 'UD']], 3),
        ], 'Sıra No', ['Ölçüm Yeri', 'Rx', 'Sonuç']);

        $this->assertSame(['1 Priz 1', '2 Priz 2', '3 Pano'], array_column($rows, 'code'));
        $this->assertSame(['Rx' => '0,8', 'Sonuç' => 'U'], $rows[1]['properties']);
        $this->assertSame(['Zx' => '0,9', 'Sonuç' => 'UD'], $rows[2]['properties']);
    }

    public function test_no_header_no_rows(): void
    {
        $this->assertSame([], PkGridRows::rows([$this->table([1, 2, 3], [['a', 'b']])], 'Sıra', ['a']));
    }
}
