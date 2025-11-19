<?php

namespace App\Service\Config;

class SubSource
{
    public const REQUIRED_FIELDS = ['type_id', 'filename', 'key', 'fields'];

    private string $name;
    private int $type_id;
    private string $filename;
    private string $key;
    private array $fields;
    private ?string $range;

    private function __construct(string $name, array $data)
    {
        $this->name = $name;
        $this->type_id = $data['type_id'];
        $this->filename = $data['filename'];
        $this->key = $data['key'];
        $this->fields = $data['fields'];
        $this->range = $data['range'] ?? null;
    }

    public static function fromArray(string $name, array $data): self
    {
        foreach (self::REQUIRED_FIELDS as $f) {
            if (!array_key_exists($f, $data)) {
                throw new \InvalidArgumentException("SubSource '{$name}' missing required field: {$f}");
            }
        }

        if (!is_int($data['type_id'])) {
            throw new \InvalidArgumentException("SubSource '{$name}': 'type_id' must be int");
        }
        if (!is_string($data['filename']) || $data['filename'] === '') {
            throw new \InvalidArgumentException("SubSource '{$name}': 'filename' must be non-empty string");
        }
        if (!is_string($data['key']) || $data['key'] === '') {
            throw new \InvalidArgumentException("SubSource '{$name}': 'key' must be non-empty string");
        }
        if (!is_array($data['fields']) || $data['fields'] === []) {
            throw new \InvalidArgumentException("SubSource '{$name}': 'fields' must be a non-empty array");
        }
        foreach ($data['fields'] as $i => $field) {
            if (!is_string($field) || $field === '') {
                throw new \InvalidArgumentException("SubSource '{$name}': fields[{$i}] must be non-empty string");
            }
        }

        $range = $data['range'] ?? null;
        if ($range !== null && !is_string($range)) {
            throw new \InvalidArgumentException("SubSource '{$name}': 'range' must be string or null");
        }

        return new self($name, $data);
    }

    /**
     * @return array<string,SubSource>
     */
    public static function listFromSource(array $source): array
    {
        $result = [];
        foreach ($source as $name => $src) {
            if (!is_array($src)) {
                throw new \InvalidArgumentException("Invalid sub-source '{$name}': expected an object.");
            }
            $result[$name] = self::fromArray($name, $src);
        }
        return $result;
    }

    public function getName(): string { return $this->name; }
    public function getTypeId(): int { return $this->type_id; }
    public function getFilename(): string { return $this->filename; }
    public function getKey(): string { return $this->key; }
    public function getFields(): array { return $this->fields; }
    public function getRange(): ?string { return $this->range; }
}
