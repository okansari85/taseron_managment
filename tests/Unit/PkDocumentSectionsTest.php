<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkDocumentSections;
use PHPUnit\Framework\TestCase;

class PkDocumentSectionsTest extends TestCase
{
    public function test_pages_split_at_heading_lines_and_text_joins_selected_sections(): void
    {
        $sections = PkDocumentSections::split([
            "Firma antedi\n## 1. GENEL BİLGİLER\n| Rapor No | 12 |",
            "## 8. KUSUR AÇIKLAMALARI\n- Dolap kilidi yok.\n## 9. SONUÇ\nUygun değildir.",
        ]);

        $this->assertSame(['', '1. GENEL BİLGİLER', '8. KUSUR AÇIKLAMALARI', '9. SONUÇ'], array_column($sections, 'heading'));
        $this->assertSame([1, 1, 2, 2], array_column($sections, 'page'));
        $this->assertSame("## 8. KUSUR AÇIKLAMALARI\n- Dolap kilidi yok.", PkDocumentSections::text($sections, [2]));
        $this->assertNull(PkDocumentSections::text($sections, []));
    }

    // #141 (NETA havalandırma): "5.2. ÖLÇÜM VERİLERİ" tek hücreli tablo olarak geldi (başlık sayılır); ölçüm listesi sonraki
    // sayfada tekrar eden kuruluş / tesisat başlıklarından sonra sürüyor (sayfa üst bilgisi zinciri koparmaz). İki hücreli tablo
    // başlık değildir.
    public function test_one_cell_table_is_a_heading_and_page_header_keeps_the_system(): void
    {
        $pages = [
            "## NETA\n## HAVALANDIRMA\n## 5.1. DOKÜMAN\n### Tablo 7\n| A.1. | Proje | G |\n### Tablo 9\n| 0 |\n| 5.2. ÖLÇÜM VERİLERİ |\n### Tablo 10\n| Menfez No | Debi |\n| 1 | 291 |",
            "## NETA\nT: 0 232\n## HAVALANDIRMA\n## PERİYODİK KONTROL RAPORU\n### Tablo 11\n| 6 | 0 |\n## 5.3. CİHAZ KONTROLÜ\n### Tablo 12\n| 0 | 1 |\n| A.1. | U |",
        ];
        $sections = PkDocumentSections::split($pages);
        $this->assertSame(['NETA', 'HAVALANDIRMA', '5.1. DOKÜMAN', '5.2. ÖLÇÜM VERİLERİ', 'NETA', 'HAVALANDIRMA', 'PERİYODİK KONTROL RAPORU', '5.3. CİHAZ KONTROLÜ'], array_column($sections, 'heading'));

        $labelled = new class extends PkDocumentSections {
            public function label(array $sections, array $systems, ?string $provider = null, string $rules = ''): array
            {
                return [0 => ['label' => 'kurulus', 'system' => null], 1 => ['label' => 'rapor_bilgisi', 'system' => null], 2 => ['label' => 'diger', 'system' => null],
                    3 => ['label' => 'sistem', 'system' => 'Debi'], 4 => ['label' => 'kurulus', 'system' => null], 5 => ['label' => 'rapor_bilgisi', 'system' => null],
                    6 => ['label' => 'diger', 'system' => null], 7 => ['label' => 'sistem', 'system' => 'Cihaz']];
            }
        };
        $debi = $labelled->texts($pages, ['Debi', 'Cihaz'])['by_system']['Debi'];
        $this->assertStringContainsString('### Tablo 10', $debi);
        $this->assertStringContainsString('### Tablo 11', $debi);
        $this->assertStringNotContainsString('T: 0 232', $debi);
    }

    public function test_repeated_page_header_labelled_as_system_keeps_the_system(): void
    {
        $pages = [
            "## HAVALANDIRMA\nPERİYODİK KONTROL RAPORU\n## 5.2. ÖLÇÜM\n### Tablo 10\n| Menfez No | Debi |\n| 1 | 291 |",
            "## PERİYODİK KONTROL RAPORU\n### Tablo 11\n| 6 | 0 |\n## DEDEKTÖR FORMU\n### Tablo 12\n| a | b |",
            "## DEDEKTÖR FORMU\n### Tablo 13\n| c | d |",
        ];
        $labelled = new class extends PkDocumentSections {
            public function label(array $sections, array $systems, ?string $provider = null, string $rules = ''): array
            {
                return [0 => ['label' => 'rapor_bilgisi', 'system' => null], 1 => ['label' => 'sistem', 'system' => 'Debi'], 2 => ['label' => 'sistem', 'system' => 'Lokal'],
                    3 => ['label' => 'sistem', 'system' => 'Dedektör'], 4 => ['label' => 'sistem', 'system' => 'Dedektör']];
            }
        };
        $bySystem = $labelled->texts($pages, ['Debi', 'Lokal', 'Dedektör'])['by_system'];
        $this->assertSame(['Debi', 'Dedektör'], array_keys($bySystem));
        $this->assertStringContainsString('### Tablo 11', $bySystem['Debi']);
        // İlk kez görülen form başlığı yeni sistemdir; sonraki sayfadaki tekrarı aynı sistemde kalır.
        $this->assertStringNotContainsString('### Tablo 12', $bySystem['Debi']);
        $this->assertStringContainsString('### Tablo 13', $bySystem['Dedektör']);
    }
}
