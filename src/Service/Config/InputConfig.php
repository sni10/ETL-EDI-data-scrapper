<?php

namespace App\Service\Config;

class InputConfig
{
    private ?int $type_id;
    private int $supplierId;
    private string|array $source;
    private array $columnMapRules;
    private ?string $range;
    private int $version;

    /** @var array<string,SubSource> */
    private array $subSources = [];

    public function __construct(array $input)
    {
        if (!isset($input['supplier_id'], $input['source'], $input['column_map_rules'], $input['version'])) {
            throw new \InvalidArgumentException('Required fields are missing in input config.');
        }

        $this->supplierId = (int)$input['supplier_id'];
        $this->type_id = isset($input['type_id']) ? ($input['type_id'] !== null ? (int)$input['type_id'] : null) : null;
        $this->source = $input['source'];
        $this->columnMapRules = $input['column_map_rules'];
        $this->range = $input['range'] ?? null;
        $this->version = (int)$input['version'];

        if (!is_array($this->columnMapRules)) {
            throw new \InvalidArgumentException('Invalid "column_map_rules": expected an array.');
        }

        if ($this->isMultiSource()) {
            // todo: decode json here
            $this->subSources = SubSource::listFromSource($this->sourceDecode($this->source));
        }

    }

    public function sourceDecode(string|array $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $str = trim($value);
        if ($str === '') {
            throw new \InvalidArgumentException('Invalid source: empty string.');
        }

        $decoded = json_decode($str, true);

        // todo: there is hidden logic, if decoding fails or it is not an array then it is a multisource type (bad practice is to use Exception for normal handling)
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid source: JSON decode error - ' . json_last_error_msg());
        }

        return $decoded;
    }

    public function isMultiSource(): bool
    {
        if (is_array($this->source)) {
            return true;
        }

        // todo: check multi source by type_id only
        if (is_string($this->source)) {
            try {
                $this->sourceDecode($this->source);
                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    public function getSupplierId(): int { return $this->supplierId; }
    public function getTypeId(): ?int { return $this->type_id; }
    public function getSource(): string|array { return $this->source; }
    public function getRange(): ?string { return $this->range; }
    public function getVersion(): int { return $this->version; }
    public function getColumnMapRules(): array { return $this->columnMapRules; }

    /**
     * @return array<string, SubSource>
     */
    public function getSubSources(): array
    {
        return $this->subSources;
    }
}
