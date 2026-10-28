<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkReportText;
use PHPUnit\Framework\TestCase;

class PkReportTextSampleTablesTest extends TestCase
{
    public function test_long_tables_keep_head_and_short_tables_stay_whole(): void
    {
        $long = implode("\n", array_map(fn ($i) => "| D{$i} | Bina |", range(1, 25)));
        $short = implode("\n", array_map(fn ($i) => "| N{$i} | 0 m³/h |", range(1, 5)));
        [$page] = PkReportText::sampleTables(["## 7.1. DOLAP LİSTESİ\n{$long}\n## 7.2. ÖLÇÜM\n{$short}"], 20, 3);
        $lines = explode("\n", $page);

        $this->assertSame(['## 7.1. DOLAP LİSTESİ', '| D1 | Bina |', '| D2 | Bina |', '| D3 | Bina |', '| … (toplam 25 satır; tamamını tablo okuyucu okur) |', '## 7.2. ÖLÇÜM'], array_slice($lines, 0, 6));
        $this->assertCount(5, array_filter($lines, fn ($line) => str_starts_with($line, '| N')));
    }
}
