<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\DataRow;
use PHPUnit\Framework\TestCase;

class DataRowTest extends TestCase
{
    public function testCreateEmptyDataRow(): void
    {
        $dataRow = new DataRow();

        $this->assertEmpty($dataRow->getFields());
        $this->assertEmpty($dataRow->toArray());
    }

    public function testCreateDataRowWithData(): void
    {
        $data = [
            'upc' => '123456789',
            'name' => 'Test Product',
            'price' => 19.99,
        ];

        $dataRow = new DataRow($data);

        $this->assertEquals($data, $dataRow->getFields());
        $this->assertEquals($data, $dataRow->toArray());
    }

    public function testSetAndGetField(): void
    {
        $dataRow = new DataRow();

        $dataRow->setField('upc', '123456789');
        $dataRow->setField('price', 29.99);

        $this->assertEquals('123456789', $dataRow->getField('upc'));
        $this->assertEquals(29.99, $dataRow->getField('price'));
    }

    public function testGetFieldReturnsNullForMissingField(): void
    {
        $dataRow = new DataRow();

        $this->assertNull($dataRow->getField('nonexistent'));
    }

    public function testHasField(): void
    {
        $dataRow = new DataRow(['upc' => '123456789']);

        $this->assertTrue($dataRow->hasField('upc'));
        $this->assertFalse($dataRow->hasField('nonexistent'));
    }
}
