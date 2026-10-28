<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkCachedCamelot;
use App\Services\Ai\PkTakip\PkTableDefinitions;
use PHPUnit\Framework\TestCase;

class PkTableDefinitionsTest extends TestCase
{
    // #18 biçimleri: sütunlu dolap listesi (blok başı "Soru / Kriter"), pompa bilgi tablosu, bütün değerleri boş pompa yuvası.
    public function test_structure_comes_from_camelot_grid(): void
    {
        $camelot = new class extends PkCachedCamelot {
            public function __construct()
            {
            }

            public function extract(string $pdfPath): array
            {
                return ['tables' => [
                    ['flavor' => 'lattice', 'page' => 6, 'bbox' => [20, 40, 560, 700], 'data' => [
                        ['', 'Soru / Kriter', '1', '2', '3'],
                        ['A', 'Dolap No', '1', '2', '3'],
                        ['B', 'Bulunduğu Yer', 'Bodrum', 'Bodrum', 'Zemin'],
                        ['', 'Soru / Kriter', '4', '5', '6'],
                        ['C', 'Dolap No', '4', '5', '6'],
                    ]],
                    ['flavor' => 'lattice', 'page' => 1, 'bbox' => [20, 230, 560, 280], 'data' => [
                        ['Marka', 'CLARKE', 'Debi (m3/h-GPM)', '1480 GPM'],
                        ['Pompa Seri No', '1', '', ''],
                    ]],
                    ['flavor' => 'lattice', 'page' => 1, 'bbox' => [20, 110, 560, 160], 'data' => [
                        ['Marka', '-', 'Debi (m3/h-GPM)', '- m3/h'],
                        ['Pompa Seri No', '- m³/h', '', ''],
                    ]],
                ]];
            }
        };
        $docling = [
            ['no' => 1, 'page' => 6, 'area' => '21,700,565,40'],
            ['no' => 2, 'page' => 1, 'area' => '20,280,565,230'],
            ['no' => 3, 'page' => 1, 'area' => '20,160,565,110'],
        ];
        $map = [
            'Dolaplar' => ['lists' => [['equipment_name' => 'Yangın Dolabı', 'tables' => [1], 'identity_label' => 'Dolap No', 'property_labels' => ['Bulunduğu Yer']]], 'singles' => []],
            'Pompalar' => ['lists' => [['equipment_name' => 'Yangın Pompası', 'tables' => [2, 3], 'identity_label' => 'Pompa Seri No', 'property_labels' => ['Marka', 'Debi (m3/h-GPM)']]], 'singles' => []],
        ];

        $out = (new PkTableDefinitions($camelot))->build('x.pdf', $docling, $map);

        $cabinet = $out['Dolaplar'][0]['instance_structure'];
        $this->assertSame(['columns', 3, ['Soru / Kriter', 'Dolap No']], [$cabinet['equipment_axis'], $cabinet['group_width'], $cabinet['header_patterns']]);
        $this->assertCount(1, $out['Pompalar']); // boş yuva (tablo 3) atlandı
        $pump = $out['Pompalar'][0];
        $this->assertSame(['none', '1'], [$pump['instance_structure']['equipment_axis'], $pump['instance_structure']['identity_value']]);
        $this->assertSame(['CLARKE', '1480 GPM'], array_column($pump['attributes'], 'value'));
    }

    // #18 3 numaralı pompa: komşu hücreden taşan birim ("- m³/h") değer değildir; rakam ya da gerçek sözcük olan değer kalır.
    public function test_overflowing_unit_is_blank(): void
    {
        $blank = new \ReflectionMethod(PkTableDefinitions::class, 'blank');
        $definitions = (new \ReflectionClass(PkTableDefinitions::class))->newInstanceWithoutConstructor();

        $this->assertTrue($blank->invoke($definitions, ['Marka' => '-', 'Pompa Seri No' => '- m³/h', 'Güç (kW)' => 'kW -']));
        $this->assertFalse($blank->invoke($definitions, ['Marka' => '-', 'Pompa Seri No' => '2']));
        $this->assertFalse($blank->invoke($definitions, ['Marka' => 'STANDART', 'Pompa Seri No' => '- m³/h']));
    }

    // PK25 biçimi: sonuç sütunla işaretli (✓ hangi sütundaysa), iki grup yan yana; hücreler sütun başlığıyla etiketlenir.
    public function test_checklist_marks_get_column_labels(): void
    {
        $camelot = new class extends PkCachedCamelot {
            public function __construct()
            {
            }

            public function extract(string $pdfPath): array
            {
                return ['tables' => [['flavor' => 'lattice', 'page' => 1, 'bbox' => [20, 40, 560, 700], 'data' => [
                    ['U: UYGUN U.D.: UYGUN DEĞİL', '', '', '', '', '', '', '', ''],
                    ['TESTLER VE KONTROLLER', 'U.', 'U.D.', 'N.U.', '', 'Sistemin Genel Kontrolleri', 'U.', 'U.D.', 'N.U.'],
                    ['4 Projeye uygun mu?', '', '✓', '', '29', 'Hidrostatik test', '', '✓', ''],
                    ['Su Deposu Kontrolleri', 'U.', 'U.D.', 'N.U.', '30', 'Korozyon', '✓', '', ''],
                    ['8 Depo kayıtları', '', '✓', '', '', '', '', '', ''],
                ]]]];
            }
        };

        $rows = explode("\n", (new PkTableDefinitions($camelot))->rows('x.pdf', [['no' => 1, 'page' => 1, 'area' => '21,700,565,40']], [1], ['TESTLER VE KONTROLLER', 'Sistemin Genel Kontrolleri', 'Su Deposu Kontrolleri']));

        $this->assertSame('| [TESTLER VE KONTROLLER] 4 Projeye uygun mu? |  | U.D.: ✓ |  | [Sistemin Genel Kontrolleri] 29 | Hidrostatik test |  | U.D.: ✓ |  |', $rows[2]);
        // Sol bölgede yeni grup başlığı (olduğu gibi kalır), sağ bölge maddesi kendi grubuyla.
        $this->assertSame('| Su Deposu Kontrolleri | U. | U.D. | N.U. | [Sistemin Genel Kontrolleri] 30 | Korozyon | U.: ✓ |  |  |', $rows[3]);
        // Sonraki sol madde yeni grubun altında.
        $this->assertSame('| [Su Deposu Kontrolleri] 8 Depo kayıtları |  | U.D.: ✓ |  |  |  |  |  |  |', $rows[4]);
    }
}
