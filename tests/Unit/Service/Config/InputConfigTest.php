<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Config;

use App\Service\Config\InputConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InputConfigTest extends TestCase
{
    public function testCreateValidInputConfig(): void
    {
        $input = [
            'supplier_id' => 100,
            'type_id' => 2,
            'source' => 'https://example.com/data.csv',
            'column_map_rules' => ['upc' => 'barcode', 'price' => 'cost'],
            'version' => 1,
            'range' => 'A1:Z1000',
        ];

        $config = new InputConfig($input);

        $this->assertEquals(100, $config->getSupplierId());
        $this->assertEquals(2, $config->getTypeId());
        $this->assertEquals('https://example.com/data.csv', $config->getSource());
        $this->assertEquals(['upc' => 'barcode', 'price' => 'cost'], $config->getColumnMapRules());
        $this->assertEquals(1, $config->getVersion());
        $this->assertEquals('A1:Z1000', $config->getRange());
    }

    public function testThrowsExceptionWhenSupplierIdMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Required fields are missing');

        new InputConfig([
            'source' => 'https://example.com/data.csv',
            'column_map_rules' => ['upc' => 'barcode'],
            'version' => 1,
        ]);
    }

    public function testThrowsExceptionWhenSourceMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Required fields are missing');

        new InputConfig([
            'supplier_id' => 100,
            'column_map_rules' => ['upc' => 'barcode'],
            'version' => 1,
        ]);
    }

    public function testNullTypeIdIsAllowed(): void
    {
        $input = [
            'supplier_id' => 100,
            'type_id' => null,
            'source' => 'https://example.com/data.csv',
            'column_map_rules' => ['upc' => 'barcode'],
            'version' => 1,
        ];

        $config = new InputConfig($input);

        $this->assertNull($config->getTypeId());
    }

    public function testNullRangeIsAllowed(): void
    {
        $input = [
            'supplier_id' => 100,
            'type_id' => 2,
            'source' => 'https://example.com/data.csv',
            'column_map_rules' => ['upc' => 'barcode'],
            'version' => 1,
        ];

        $config = new InputConfig($input);

        $this->assertNull($config->getRange());
    }
}
