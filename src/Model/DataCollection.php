<?php
namespace App\Model;

class DataCollection
{
    /**
     * @var DataRow[]
     */
    protected array $dataRows = [];

    protected array $rules = [];

    public function add(DataRow $dataRow): void
    {
        $this->dataRows[] = $dataRow;
    }

    public function addNoIndex(DataRow $dataRow): void
    {
        $this->dataRows[] = $dataRow;
    }

    /**
     * @return DataRow[]
     */
    public function getRows(): array
    {
        return $this->dataRows;
    }

    public function count(): int
    {
        return count($this->dataRows);
    }

    public function setRules(array $rules): void
    {
        $this->rules = $rules;
    }

    public function getRules(): array
    {
        return $this->rules;
    }
}
