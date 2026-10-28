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

    // #122 sayfa kayması: haritada yalnız başlıksız devam tablosu (3. sayfa); başlık bir önceki, aynı genişlikteki tablodan.
    // Sütun sayısı farklı önceki tablo başka bir tablodur: tarif kurulmaz.
    public function test_continuation_table_takes_header_from_previous_table(): void
    {
        $camelot = new class extends PkCachedCamelot {
            public function __construct()
            {
            }

            public function extract(string $pdfPath): array
            {
                return ['tables' => [
                    ['flavor' => 'lattice', 'page' => 1, 'bbox' => [20, 400, 560, 700], 'data' => [['Rapor No', 'NT/24'], ['Tarih', '14.03.24']]],
                    ['flavor' => 'lattice', 'page' => 2, 'bbox' => [20, 40, 560, 700], 'data' => [
                        ['Sıra No', 'Ölçüm Yeri', 'In(A)', 'Sonuç'],
                        ['1', 'Depo Panosu', '63', 'U.D'],
                    ]],
                    ['flavor' => 'lattice', 'page' => 3, 'bbox' => [20, 460, 560, 740], 'data' => [
                        ['36', 'B-04 Panosu', '32', 'U.D'],
                    ]],
                ]];
            }
        };
        $docling = [
            ['no' => 1, 'page' => 1, 'area' => '20,700,560,400'],
            ['no' => 2, 'page' => 2, 'area' => '20,700,560,40'],
            ['no' => 3, 'page' => 3, 'area' => '20,740,560,460'],
        ];
        $list = ['equipment_name' => 'Ölçüm Noktası', 'identity_label' => 'Sıra No', 'property_labels' => ['Ölçüm Yeri', 'In(A)']];
        $definitions = new PkTableDefinitions($camelot);

        $out = $definitions->build('x.pdf', $docling, ['Topraklama' => ['lists' => [$list + ['tables' => [3]]], 'singles' => []]]);
        $structure = $out['Topraklama'][0]['instance_structure'];
        $this->assertSame(['rows', ['Sıra No', 'Ölçüm Yeri', 'In(A)']], [$structure['equipment_axis'], $structure['header_patterns']]);

        $wrong = array_merge($list, ['tables' => [2], 'identity_label' => 'Kayıt No']);
        $this->assertSame([], $definitions->build('x.pdf', $docling, ['Topraklama' => ['lists' => [$wrong], 'singles' => []]]));
    }

    // #141: ajan debi listesini hem satır satır tek ekipman hem tipe göre iki liste diye verdi; bir liste tarifi kalır, satırlar
    // tek ekipman olarak tekrar eklenmez.
    public function test_list_rows_given_as_singles_and_duplicate_lists_are_dropped(): void
    {
        $camelot = new class extends PkCachedCamelot {
            public function __construct()
            {
            }

            public function extract(string $pdfPath): array
            {
                return ['tables' => [['flavor' => 'lattice', 'page' => 1, 'bbox' => [20, 40, 560, 700], 'data' => [
                    ['Menfez No', 'Cihaz No', 'Tipi', 'Debi'],
                    ['1', '1 NUMARA', 'VANTİLATÖR', '291,3'],
                    ['2', '2 NUMARA', 'ASPİRATÖR', '162,0'],
                ]]]];
            }
        };
        $list = ['tables' => [1], 'identity_label' => 'Menfez No', 'property_labels' => ['Cihaz No', 'Tipi', 'Debi']];
        $map = ['Debi' => [
            'lists' => [['equipment_name' => 'ASPİRATÖR'] + $list, ['equipment_name' => 'VANTİLATÖR'] + $list],
            'singles' => [
                ['equipment_name' => '1 NUMARA', 'table' => 1, 'identity_value' => '1', 'properties' => [['label' => 'Cihaz No', 'value' => '1 NUMARA']]],
                ['equipment_name' => '2 NUMARA', 'table' => 1, 'identity_value' => '2', 'properties' => [['label' => 'Cihaz No', 'value' => '2 NUMARA']]],
            ],
        ]];

        $out = (new PkTableDefinitions($camelot))->build('x.pdf', [['no' => 1, 'page' => 1, 'area' => '20,700,560,40']], $map);

        $this->assertCount(1, $out['Debi']);
        $this->assertSame(['ASPİRATÖR', 'rows'], [$out['Debi'][0]['equipment_name'], $out['Debi'][0]['instance_structure']['equipment_axis']]);
    }

    // #18: ajan pano listesine pompanın kimlik etiketini yazdı; pano tablosunda yok, geride aynı genişlikte tek ekipmanlı pompa
    // tablosu var: devam tablosu sayılmaz, pompa pano diye ikinci kez eklenmez.
    public function test_single_equipment_table_is_not_a_list_header(): void
    {
        $camelot = new class extends PkCachedCamelot {
            public function __construct()
            {
            }

            public function extract(string $pdfPath): array
            {
                return ['tables' => [
                    ['flavor' => 'lattice', 'page' => 1, 'bbox' => [20, 230, 560, 280], 'data' => [['Marka', 'STANDART'], ['Pompa Seri No', '3']]],
                    ['flavor' => 'lattice', 'page' => 1, 'bbox' => [20, 110, 560, 160], 'data' => [['Marka', 'STANDART'], ['Panel Tipi', 'Otomatik']]],
                ]];
            }
        };
        $docling = [['no' => 1, 'page' => 1, 'area' => '20,280,560,230'], ['no' => 2, 'page' => 1, 'area' => '20,160,560,110']];
        $map = ['Pompalar' => ['lists' => [['equipment_name' => 'Pompa Kontrol Panosu', 'tables' => [2], 'identity_label' => 'Pompa Seri No', 'property_labels' => ['Marka']]], 'singles' => []]];

        $this->assertSame([], (new PkTableDefinitions($camelot))->build('x.pdf', $docling, $map));
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
