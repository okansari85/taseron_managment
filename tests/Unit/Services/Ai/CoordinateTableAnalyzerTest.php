<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\CoordinateTableAnalyzer;
use PHPUnit\Framework\TestCase;

class CoordinateTableAnalyzerTest extends TestCase
{
    public function test_maps_status_cells_to_equipment_columns_by_coordinates(): void
    {
        $analyzer = new CoordinateTableAnalyzer();

        $result = $analyzer->analyze([
            [
                'page' => 7,
                'words' => [
                    ['text' => 'YD1', 'x' => 100, 'y' => 100, 'width' => 20, 'height' => 10],
                    ['text' => 'YD2', 'x' => 180, 'y' => 100, 'width' => 20, 'height' => 10],
                    ['text' => 'YD3', 'x' => 260, 'y' => 100, 'width' => 20, 'height' => 10],
                    ['text' => '5.41', 'x' => 20, 'y' => 140, 'width' => 25, 'height' => 10],
                    ['text' => 'U', 'x' => 101, 'y' => 140, 'width' => 8, 'height' => 10],
                    ['text' => 'UD', 'x' => 181, 'y' => 140, 'width' => 12, 'height' => 10],
                    ['text' => 'U', 'x' => 261, 'y' => 140, 'width' => 8, 'height' => 10],
                ],
            ],
        ], [
            ['code' => 'YD1'],
            ['code' => 'YD2'],
            ['code' => 'YD3'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('5.41', $result[0]['code']);
        $this->assertSame(['YD1', 'YD2', 'YD3'], $result[0]['equipment_refs']);
    }
}
