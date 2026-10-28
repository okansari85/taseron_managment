<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkFireAgents;
use PHPUnit\Framework\TestCase;

class PkFireAgentsTest extends TestCase
{
    // #18: tablo 14'ün dolapları tek tek, devamı (15-18) liste geldi → tablo 14 listenin başına; tek pompa tablosu yerinde kalır.
    public function test_table_with_several_singles_joins_the_list(): void
    {
        $agents = (new \ReflectionClass(PkFireAgents::class))->newInstanceWithoutConstructor();
        $dolap = 'Yangın Dolabı';
        $found = $agents->listTables([
            'lists' => [['equipment_name' => $dolap, 'tables' => [15, 16, 17, 18], 'identity_label' => 'Dolap No', 'property_labels' => []]],
            'singles' => [
                ['equipment_name' => $dolap, 'table' => 14, 'identity_value' => 'Dolap No 1'],
                ['equipment_name' => $dolap, 'table' => 14, 'identity_value' => 'Dolap No 2'],
                ['equipment_name' => 'Yangın Pompası', 'table' => 6, 'identity_value' => '1 NUMARALI POMPA'],
            ],
        ]);

        $this->assertSame([14, 15, 16, 17, 18], $found['lists'][0]['tables']);
        $this->assertSame(['1 NUMARALI POMPA'], array_column($found['singles'], 'identity_value'));
    }

    // PK.239: pompa tablosu başka adlı listelere de verilmiş; tablo tekillerin türünde kalır, öbür listeler düşer.
    public function test_table_belongs_to_one_equipment_type(): void
    {
        $agents = (new \ReflectionClass(PkFireAgents::class))->newInstanceWithoutConstructor();
        $found = $agents->listTables([
            'lists' => [
                ['equipment_name' => 'Pompa Kontrol Panosu', 'tables' => [3], 'identity_label' => 'Makine Kodu'],
                ['equipment_name' => 'Yangın Pompası', 'tables' => [3], 'identity_label' => 'Pompa No'],
            ],
            'singles' => [
                ['equipment_name' => 'Yangın Pompası', 'table' => 3, 'identity_value' => '1'],
                ['equipment_name' => 'Yangın Pompası', 'table' => 3, 'identity_value' => '2'],
            ],
        ]);

        $this->assertSame(['Yangın Pompası'], array_column($found['lists'], 'equipment_name'));
        $this->assertSame([], $found['singles']);
    }
}
