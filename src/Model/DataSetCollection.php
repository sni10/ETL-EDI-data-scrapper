<?php
// src/Model/DataSetCollection.php
namespace App\Model;

class DataSetCollection extends DataCollection
{
    // Default unique index for mapped datasets
    protected string $uniqueIndex = 'upc';

    public function setUniqueIndex(string $fieldName): void
    {
        $this->uniqueIndex = $fieldName;
    }

    public function getUniqueIndex(): string
    {
        return $this->uniqueIndex;
    }

    /**
     * Apply rules when adding/updating a row with the same unique key
     */
    public function applyRules(DataRow $dataRow): DataRow
    {
        if (empty($this->rules)) {
            return $dataRow;
        }

        $key = $dataRow->getField($this->uniqueIndex);

        if ($key === null) {
            return $dataRow;
        }
        $oldRow = $this->getDataByUniqueIndex($key);

        foreach ($this->rules as $targetField => $rule) {
            if (!$dataRow->hasField($targetField)) {
                continue;
            }

            $newValue = $dataRow->getField($targetField);
            $oldValue = $oldRow?->getField($targetField);

            switch ($rule) {
                case 'addArray':
                    $dataRow->setField($targetField, array_merge($oldValue ?? [], [$newValue]));
                    break;
                case 'max':
                    $dataRow->setField($targetField, $oldValue !== null ? max($oldValue, $newValue) : $newValue);
                    break;
                case 'min':
                    $dataRow->setField($targetField, $oldValue !== null ? min($oldValue, $newValue) : $newValue);
                    break;
                default:
                    // leave new value
                    break;
            }
        }

        return $dataRow;
    }

    public function getDataByUniqueIndex(string $uniqueIndexValue): ?DataRow
    {
        return $this->dataRows[$uniqueIndexValue] ?? null;
    }

    public function add(DataRow $dataRow): void
    {
        $key = $dataRow->getField($this->uniqueIndex);
        if ($key !== null && $key !== '') {
            $this->dataRows[$key] = $this->applyRules($dataRow);
        }
    }

    public static function createFromCollection(DataCollection $dataCollection, string $key): self
    {
        $set = new self();
        $set->setUniqueIndex($key);
        foreach ($dataCollection->getRows() as $row) {
            $set->add($row);
        }
        return $set;
    }

    public function addFieldsFromCollection(DataCollection $dataCollection, string $key, array $fieldList): self
    {
        foreach ($dataCollection->getRows() as $row) {
            $unique = $row->getField($key);
            if ($unique === null || $unique === '') {
                continue;
            }

            if (!isset($this->dataRows[$unique])) {
                continue;
            }

            $targetRow = $this->dataRows[$unique];
            foreach ($fieldList as $fieldName) {
                if ($row->hasField($fieldName)) {
                    $targetRow->setField($fieldName, $row->getField($fieldName));
                }
            }

            $this->dataRows[$unique] = $targetRow;
        }

        return $this;
    }
}
