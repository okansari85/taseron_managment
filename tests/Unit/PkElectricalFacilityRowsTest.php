<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkDocumentSections;
use App\Services\Ai\PkTakip\PkElectricalReportReader;
use App\Services\Ai\PkTakip\PkElectricalAgents;
use App\Services\Ai\PkTakip\PkFindingsPass;
use Tests\TestCase;

class PkElectricalFacilityRowsTest extends TestCase
{
    public function test_generator_letters_are_repaired_in_specs(): void
    {
        $semantic = ['extracted_data' => ['equipment_specs' => [['key' => 'calisma', 'value' => 'Otomaţk', 'raw_value' => 'Otomaţk']]]];

        $this->assertSame('Otomatik', \App\Services\Ai\PkTakip\PkSingleEquipmentReader::repairGeneratorLetters($semantic)['extracted_data']['equipment_specs'][0]['value']);
    }

    public function test_tube_warnings_window_is_under_the_written_heading(): void
    {
        $lines = ['Form', 'İKAZ VE ÖNERİLER', 'Tüp eksik.', 'SONUÇ'];

        $this->assertSame(['Tüp eksik.', 'SONUÇ'], \App\Services\Ai\PkTakip\PkBulkListPrompt::warningsWindow($lines, 'İKAZ VE ÖNERİLER'));
        $this->assertSame([], \App\Services\Ai\PkTakip\PkBulkListPrompt::warningsWindow($lines, 'Uydurma Başlık'));
        $this->assertSame([], \App\Services\Ai\PkTakip\PkBulkListPrompt::warningsWindow($lines, null));
    }

    public function test_lightning_measurement_must_be_written_in_the_report(): void
    {
        $lines = ['Topraklama Ölçüm Değeri (Ω)', '1,18', 'Topraklama Sınır Değeri (Ω)', '10,00'];

        $this->assertSame([['key' => 'Topraklama Ölçüm Değeri (Ω)', 'value' => '1,18'], ['key' => 'Topraklama Sınır Değeri (Ω)', 'value' => '10,00']], PkElectricalAgents::lightningFacts($lines, ['olcum' => '1,18', 'sinir' => '10,00']));
        $this->assertSame([], PkElectricalAgents::lightningFacts($lines, ['olcum' => '2,5', 'sinir' => 'Topraklama Sınır Değeri (Ω)']));
    }

    public function test_lightning_conclusion_includes_decision_heading_and_stops_at_footer(): void
    {
        $lines = ['## 7. KUSUR', 'Direk paslı.', '## 8. SONUÇ', 'UYARI metni', '## KULLANILMASI UYGUNDUR.', '## NETA'];
        $found = ['kusur' => '## 7. KUSUR', 'sonuc' => '## 8. SONUÇ', 'karar' => '## KULLANILMASI UYGUNDUR.'];

        $this->assertSame(['kusur' => 'Direk paslı.', 'not' => '', 'sonuc' => "UYARI metni\nKULLANILMASI UYGUNDUR.", 'bulundu' => true], PkElectricalAgents::lightningCut($lines, $found));
        $this->assertSame('UYARI metni', PkElectricalAgents::lightningCut($lines, [...$found, 'karar' => null])['sonuc']);
        $this->assertFalse(PkElectricalAgents::lightningCut($lines, [])['bulundu']);
    }

    public function test_table_findings_are_only_for_cabinet_reports(): void
    {
        $report = fn (array $names, string $slug = 'yangin-tesisati') => ['extracted_data' => ['equipment_type' => ['slug' => $slug]], 'template' => ['fire_systems' => ['systems' => array_map(fn ($name) => ['system_name' => $name], $names)]]];

        $this->assertTrue(\App\Services\Ai\PkTakip\PkReportAnalyzer::isCabinetReport($report(['Yangın Dolapları ve Hortum Makaraları'])));
        $this->assertFalse(\App\Services\Ai\PkTakip\PkReportAnalyzer::isCabinetReport($report(['Gazlı Söndürme Sistemi', 'Belge ve Kayıt Kontrolleri'])));
        $this->assertFalse(\App\Services\Ai\PkTakip\PkReportAnalyzer::isCabinetReport($report(['Yangın Dolapları ve Hortum Makaraları'], 'forklift')));
    }

    public function test_electrical_sections_keep_notes_apart(): void
    {
        $lines = ['7. NOTLAR', '3-Yönetim odası ölçülemedi.', '## 8. SONUÇ', 'Panoda etiket yok.', 'Kullanımı uygun değildir.', '## 9. ONAY'];

        $this->assertSame(['kusur' => '', 'not' => '3-Yönetim odası ölçülemedi.', 'sonuc' => "Panoda etiket yok.\nKullanımı uygun değildir.", 'bulundu' => true], PkElectricalAgents::electricalCut($lines, ['not' => '7. NOTLAR', 'sonuc' => '## 8. SONUÇ']));
        $this->assertSame('Panoda etiket yok.', PkElectricalAgents::electricalCut(['## 8. SONUÇ', 'Panoda etiket yok.', '## KULLANILMASI UYGUNDUR.', '## FİRMA'], ['sonuc' => '## 8. SONUÇ', 'karar' => null])['sonuc']);
        $this->assertSame("Metin\nKULLANILMASI UYGUNDUR.", PkElectricalAgents::electricalCut(['## 8. SONUÇ', 'Metin', '## KULLANILMASI UYGUNDUR.', '## FİRMA'], ['sonuc' => '## 8. SONUÇ', 'karar' => '## KULLANILMASI UYGUNDUR.'])['sonuc']);
        $this->assertSame('Rölesi yok.', PkElectricalAgents::electricalCut(['## 6. ÖLÇÜM', '## 7. KUSUR / NOTLAR', 'Rölesi yok.', '## 8. SONUÇ', 'x'], ['sonuc' => '## 8. SONUÇ'])['kusur']);
        $this->assertFalse(PkElectricalAgents::isElectricalHeading('12.07.2025'));
        $this->assertFalse(PkElectricalAgents::isElectricalHeading('1. kat tali panoda etiketleme yapılmamıştır.'));
        $this->assertTrue(PkElectricalAgents::isElectricalHeading('KUSUR AÇIKLAMALARI:'));
        $this->assertSame('PROJE YOK', PkElectricalAgents::electricalCut(['KUSUR:', 'PROJE YOK', 'SONUÇ:', 'x'], ['kusur' => 'KUSUR:', 'sonuc' => 'SONUÇ:'])['kusur']);
    }

    public function test_electrical_leftover_sentence_in_finding_line_is_a_finding(): void
    {
        $text = "Tali panoda etiket yok. Pano kapağı yok.\nKullanımı uygun değildir.\nBu rapor iki nüshadır.";
        $items = [['text' => 'Tali panoda etiket yok.', 'system' => 'Panolar', 'tur' => 'bulgu'], ['text' => 'Kullanımı uygun değildir.', 'system' => null, 'tur' => 'karar']];

        $this->assertSame([['text' => 'Pano kapağı yok.', 'system' => 'Panolar', 'tur' => 'bulgu']], PkElectricalAgents::electricalLeftovers($text, $items));
        $this->assertSame([], PkElectricalAgents::electricalLeftovers("A panosu yok. Kullanımı uygun değildir.", [['text' => 'A panosu yok.', 'tur' => 'bulgu'], ['text' => 'Kullanımı uygun değildir.', 'tur' => 'karar']]));
    }

    public function test_lines_repeated_on_pages_are_removed_from_notes(): void
    {
        $pages = ["NOT A\n1/2 Bu rapor iki sayfadır\nFORM-KOD", "2/2 Bu rapor iki sayfadır\nFORM-KOD\nKIRIK PANO"];
        $this->assertSame("NOT A\nKIRIK PANO", \App\Services\Ai\PkTakip\PkElectricalAgents::withoutFooters("NOT A\n1/2 Bu rapor iki sayfadır\nFORM-KOD\nKIRIK PANO", $pages));
        $this->assertSame('FORM-KOD', \App\Services\Ai\PkTakip\PkElectricalAgents::withoutFooters('FORM-KOD', $pages, ['FORM-KOD']));
        $this->assertSame('NOT A', \App\Services\Ai\PkTakip\PkElectricalAgents::withoutFooters("NOT A\nADRES SATIRI", ["NOT A\nADRES SATIRI"], [], ["ADRES SATIRI", "ADRES SATIRI\nNOT A"]));
    }

    public function test_fire_line_split_must_keep_the_written_text(): void
    {
        $line = 'A DOLABI KIRIK. -B DOLABI YOK.';
        $this->assertSame(['A DOLABI KIRIK.', '-B DOLABI YOK.'], \App\Services\Ai\PkTakip\PkFindingsPass::sameTextSplit($line, ['A DOLABI KIRIK.', '-B DOLABI YOK.']));
        $this->assertSame([$line], \App\Services\Ai\PkTakip\PkFindingsPass::sameTextSplit($line, ['A DOLABI KIRIK.']));
    }

    public function test_conclusion_is_cut_after_decision_line(): void
    {
        $this->assertSame("Bilgi.\nKullanımı uygundur.", PkElectricalAgents::cutAfter(['Bilgi.', 'Kullanımı uygundur.', 'Sayfa altı'], 'Kullanımı uygundur.'));
        $this->assertNull(PkElectricalAgents::cutAfter(['Bilgi.'], 'yok'));
    }

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

    public function test_warnings_catch_contradictions_without_changing_values(): void
    {
        $this->assertSame(['Rapor "uygun değil" diyor ama bulgu bulunamadı; kusur bölümünü kontrol edin.'], PkElectricalReportReader::warnings([
            'status' => 'uygun_degil', 'general_findings' => [], 'systems' => [['name' => 'Ölçüm', 'findings' => []]],
        ]));
        $this->assertSame([], PkElectricalReportReader::warnings(['status' => 'uygun_degil', 'general_findings' => ['Pano kapağı yok'], 'systems' => []]));
        $this->assertSame(['Rapor "uygun" diyor ama bulgu var; sonucu kontrol edin.'], PkElectricalReportReader::warnings(['status' => 'uygun', 'general_findings' => ['x'], 'systems' => []]));
        $this->assertSame(['Raporun genel sonucu okunamadı.'], PkElectricalReportReader::warnings(['status' => null]));
    }

    // Başlık yapıdan bulunur: veri satırının çoğu rakamdır, cihaz satırı 3 hücrelidir; başlık öndeki tablodaysa oradan.
    public function test_sections_are_cut_from_heading_lines(): void
    {
        $lines = ['5. Ölçüm', 'satır', '6. KUSUR', 'kusur 1', 'kusur 2', '7. NOTLAR', 'not 1', '8. SONUÇ', 'sonuç', '9. YETKİLİ', 'imza'];
        $cut = PkElectricalAgents::cutSections($lines, [['line' => 8, 'kind' => 'sonuc'], ['line' => 3, 'kind' => 'kusur'], ['line' => 6, 'kind' => 'not'], ['line' => 10, 'kind' => 'diger'], ['line' => 99, 'kind' => 'kusur']]);
        $this->assertSame(['kusur' => "kusur 1\nkusur 2", 'not' => 'not 1', 'sonuc' => 'sonuç'], $cut);
    }

    public function test_list_header_is_found_by_structure(): void
    {
        $t = fn (int $no, array $rows) => ['no' => $no, 'rows' => $rows];
        $run = [$t(1, ['KONTROL | UYGUN', 'Sıra | Ölçüm Noktası | In (A) | Ölçülen | Not', '1 Ana | 40 | C | 0,55 | Not 1']), $t(2, ['3 | Priz | 16 | 0,9 | Not 6'])];

        $this->assertSame(['Sıra', 'Ölçüm Noktası', 'In (A)', 'Ölçülen', 'Not'], PkElectricalAgents::listHeader($run, ['t2']));
        $this->assertNull(PkElectricalAgents::listHeader([$t(2, ['3 | Priz | 16 | 0,9 | 2'])], ['t2']));
    }

    public function test_written_text_keeps_prose_and_one_cell_rows_only(): void
    {
        $this->assertSame("## 6. KUSUR\nPriz topraksız.\nGaraj panosu açık.", PkElectricalAgents::writtenText(["## 6. KUSUR\n### Tablo 1\n| Sıra | Yer |\n| 1 | Priz |\nPriz topraksız.\n### Tablo 2\n| 0 |\n| Garaj panosu açık. |"]));
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
