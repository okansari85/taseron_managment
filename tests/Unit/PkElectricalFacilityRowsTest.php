<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkDocumentSections;
use App\Services\Ai\PkTakip\PkElectricalReportReader;
use App\Services\Ai\PkTakip\PkElectricalAgents;
use App\Services\Ai\PkTakip\PkFindingsPass;
use Tests\TestCase;

class PkElectricalFacilityRowsTest extends TestCase
{
    public function test_rows_come_from_lattice_tables_of_first_pages_with_clean_cells(): void
    {
        $tables = [
            ['flavor' => 'lattice', 'page' => 1, 'data' => [['Enerji Sağlayan Kuruluş      UEDAŞ', '', "Şebeke Tipi", "☐IT\n☒TT"], ['', '', '', '']]],
            ['flavor' => 'stream', 'page' => 1, 'data' => [['çizgisiz okuma', 'x']]],
            ['flavor' => 'lattice', 'page' => 4, 'data' => [['ileri sayfa', 'x']]],
        ];

        $this->assertSame(['Enerji Sağlayan Kuruluş UEDAŞ | Şebeke Tipi | ☐IT ☒TT'], PkElectricalAgents::facilityRows($tables));
    }

    // Topraklama: ölçüm başlığı kriter satırlarının altında; kutu sol-üst → sağ-alt; uzun cümle sütun başlığı adayı değil.
    public function test_lattice_tables_give_area_rows_and_labels_from_any_row(): void
    {
        $out = PkElectricalAgents::latticeTables([
            ['flavor' => 'stream', 'page' => 1, 'bbox' => [1, 2, 3, 4], 'data' => [['x']]],
            ['flavor' => 'lattice', 'page' => 2, 'bbox' => [10, 20, 500, 700], 'data' => [['KONTROL', ''], ['Sıra', 'Ölçülen Değer'], ['1', '0,55'], [str_repeat('uzun ', 20), '']]],
        ]);

        $this->assertCount(1, $out);
        $this->assertSame(1, $out[0]['no']);
        $this->assertSame(2, $out[0]['page']);
        $this->assertSame('10,700,500,20', $out[0]['area']);
        $this->assertSame('Sıra | Ölçülen Değer', $out[0]['rows'][1]);
        $this->assertSame(['KONTROL', 'Sıra', 'Ölçülen Değer'], $out[0]['labels']);
    }

    public function test_measurement_rows_do_not_enter_kusur_decision(): void
    {
        $this->assertNull(PkFindingsPass::kusurLine('| 1 | Ana Kesici | 25 | C | 250 | 0,59 |'));
        $this->assertSame('6. KUSUR AÇIKLAMALARI', PkFindingsPass::kusurLine('| 6. KUSUR AÇIKLAMALARI |'));
        $this->assertSame('| 1 | Pano kapağı yok |', PkFindingsPass::kusurLine('| 1 | Pano kapağı yok |'));
        $this->assertSame('Priz topraksız.', PkFindingsPass::kusurLine('  Priz topraksız. '));
    }

    // 590050-topraklama: başlık kriter tablosunda (13 sütun), satırlar 10 sütunlu sayfalarda; araya bilgi tablosu girince liste biter.
    public function test_consecutive_same_column_tables_are_one_list_with_preceding_table(): void
    {
        $t = fn (int $no, int $columns, int $rows = 3) => ['no' => $no, 'columns' => $columns, 'rows' => array_fill(0, $rows, 'x')];
        $runs = PkElectricalAgents::listRuns([$t(1, 13), $t(2, 10), $t(3, 10), $t(4, 1), $t(5, 7)], ['t2', 't3', 't5']);

        $this->assertSame([[1, 2, 3], [4, 5]], array_map(fn (array $run) => array_column($run, 'no'), $runs));
        // #126: tek kayıtlık "Rapor No" tablosu liste değildir, öndeki tablonun satırları sayılmaz.
        $this->assertSame([], PkElectricalAgents::listRuns([$t(1, 13, 20), $t(2, 6, 2)], ['t2']));
    }

    public function test_small_table_of_a_facility_row_is_selected_large_table_is_not(): void
    {
        $small = [['row' => 'Kullanım Amacı | Depo', 'table' => 0], ['row' => 'Proje var mı | Var | Kesit uygun mu | U', 'table' => 0]];
        $large = array_fill(0, 7, ['row' => 'Kablo | U', 'table' => 1]);
        $large[0] = ['row' => 'Şebeke Tipi | TT', 'table' => 1];

        $this->assertSame([0, 1, 2], PkElectricalAgents::facilitySelected([...$small, ...$large], ['tesis', 'kriter', 'tesis', 'kriter', 'kriter', 'kriter', 'kriter', 'kriter', 'kriter']));
    }

    public function test_failed_cell_is_a_whole_cell_not_a_sentence(): void
    {
        $this->assertTrue(PkElectricalAgents::hasFailedCell("| 1 Proje mevcut mu? | U.D |\n| 2 Bara | U |"));
        $this->assertTrue(PkElectricalAgents::hasFailedCell('| Pano | UYGUN DEĞİL |'));
        $this->assertFalse(PkElectricalAgents::hasFailedCell("| 1 Bara | U |\n| Değerlendirme uygun değil olarak yapılmıştır | x |\nU.D"));
    }

    public function test_warnings_catch_contradictions_without_changing_values(): void
    {
        $this->assertSame(['Rapor "uygun değil" diyor ama bulgu bulunamadı; kusur bölümünü kontrol edin.', 'Ölçüm: 1 satır "uygun değil" ama bulgu yok.'], PkElectricalReportReader::warnings([
            'status' => 'uygun_degil', 'general_findings' => [], 'systems' => [['name' => 'Ölçüm', 'findings' => []]],
            'equipment_rows' => [['system_name' => 'Ölçüm', 'properties' => ['SONUÇ' => 'UYGUN DEĞİL']], ['system_name' => 'Ölçüm', 'properties' => ['SONUÇ' => 'UYGUN']]],
        ]));
        $this->assertSame([], PkElectricalReportReader::warnings(['status' => 'uygun_degil', 'general_findings' => ['Pano kapağı yok'], 'systems' => []]));
        $this->assertSame(['Rapor "uygun" diyor ama bulgu var; sonucu kontrol edin.'], PkElectricalReportReader::warnings(['status' => 'uygun', 'general_findings' => ['x'], 'systems' => []]));
        $this->assertSame(['Raporun genel sonucu okunamadı.'], PkElectricalReportReader::warnings(['status' => null]));
    }

    // 11930-elektrik: not ve sonuç tek sütunlu tabloda; sütunlu tablo kusur ajanına gitmez.
    public function test_text_tables_are_only_single_column_tables(): void
    {
        $this->assertSame("8. SONUÇ\nAna panoda etiket yok.", PkDocumentSections::textTables(["### Tablo 1\n| Kablo | UYGUN |\n### Tablo 2\n| 0 |\n| 8. SONUÇ |\n| Ana panoda etiket yok. |"]));
        $this->assertNull(PkDocumentSections::textTables(["### Tablo 1\n| Kablo | UYGUN |"]));
    }

    // #126: "Var" yerine komşu hücre yazılamaz değil, ama satırda olmayan değer ve boş alan elenir; işaret değere girmez.
    public function test_pair_must_be_written_in_the_row(): void
    {
        $row = 'Enerji Sağlayan Kuruluş UEDAŞ | Şebeke Tipi | ☐IT ☒TT | Son Kontrol Tarihi';

        $this->assertSame(['label' => 'Şebeke Tipi', 'value' => 'TT'], PkElectricalAgents::facilityPair($row, 'Şebeke Tipi', '☒TT'));
        $this->assertNull(PkElectricalAgents::facilityPair($row, 'Enerji Sağlayan Kuruluş', 'TEDAŞ'));
        $this->assertNull(PkElectricalAgents::facilityPair($row, 'Son Kontrol Tarihi', ''));
        $this->assertNull(PkElectricalAgents::facilityPair($row, 'Uydurma Etiket', 'TT'));
    }
}
