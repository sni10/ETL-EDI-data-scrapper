<?php
// src/Service/Mapper/Mapper.php
namespace App\Service\Mapper;

use App\Model\DataCollection;
use App\Model\DataRow;
use App\Model\DataSetCollection;
use Psr\Log\LoggerInterface;

class Mapper
{
    private LoggerInterface $logger;
    public function __construct(
        LoggerInterface $logger,
    ) {
        $this->logger = $logger;
    }

    private function asinValidate($value): ?string
    {

        $value = strtoupper(trim($value));
        if (preg_match('/^[A-Z0-9]{10}$/', $value)) {
            return $value;
        }
        return null;
    }

    private function cleanString($value): string
    {
        return preg_replace('/[^a-zа-я\d.]/ui', '', (string)$value) ?? '';
    }

    private function cleanUPC($value): string
    {
        return substr($this->cleanString($value), 0, 13);
    }

    private function cleanInteger($value): int
    {
        return intval(preg_replace('/[^\d]/', '', $value));
    }

    private function cleanFloat($value): float
    {
        $value = str_replace(",", ".", $value);
        $value = $this->cleanString($value);
        $value = preg_replace('/[^\d.]/', '', $value);
        return floatval($value);
    }

    /**
     * @throws \Exception
     */
    public function mapColumns(DataCollection $dataCollection, array $mappingConfig, int $supplier_id, int $version): DataSetCollection
    {
        $rules = [];
        foreach ($mappingConfig as $targetField => $sourceField) {
            // add exceptions
            if (is_array($sourceField)) {

                if (count($sourceField) < 2) {
                    throw new \InvalidArgumentException("Invalid mapping configuration for field '$targetField'. Expected an array with at least two elements (source field and rule).");
                }

                $rules[$targetField] = $sourceField[1];
            }

        }

        // $rules = { ОБРАЗЕЦ
        //     "upc": "UPC",
        //     "qty": ["Quantity on hand units", "min"],
        //     "price": ["Item price", "max"],
        //     "status": ["Sublocation", "addArray"]
        // }

        $mappedDataCollection = new DataSetCollection($rules);

        $lastItem = null;
        foreach ($dataCollection->getRows() as $dataRow) {
            $mappedRowData = [];
            $missingFields = [];
            foreach ($mappingConfig as $targetField => $sourceField) {

                if (is_array($sourceField)) {
                    $sourceField = $sourceField[0] ?? null;
                }

                if (!$dataRow->hasField($sourceField)) {
                    $missingFields[] = $sourceField;
                }

                $rawValue = $dataRow->getField($sourceField);
                switch ($targetField) {
                    case 'asin':
                        $value = $this->asinValidate($rawValue);
                        break;
                    case 'upc':
                        $value = $this->cleanUPC($rawValue);
                        break;
                    case 'price':
                        $value = $this->cleanFloat($rawValue);
                        break;
                    case 'qty':
                        $value = $this->cleanInteger($rawValue);
                        break;
                    default:
                        $value = $rawValue;
                        break;
                }

                $mappedRowData[$targetField] = $value;
            }

            $lastItem = $dataRow;
            $mappedDataRow = new DataRow($mappedRowData);
            $mappedDataRow->setField('supplier_id', $supplier_id);
            $mappedDataRow->setField('version', $version);
            $mappedDataCollection->add($mappedDataRow);
        }

        if (!empty($missingFields)) {
            $this->logger->error('Missing fields in data row for mapping.', [
                'supplier_id' => $supplier_id,
                'missingFields' => $missingFields,
                'dataRow' => $lastItem->getFields()
            ]);
            throw new \Exception('Missing fields for in data row for mapping.');
        }

        return $mappedDataCollection;
    }

}
