<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use InvalidArgumentException;

final class FieldTypeRegistry
{
    /** @var array<string, FieldType> */
    private array $types = [];

    /** @param iterable<FieldType> $types */
    public function __construct(iterable $types)
    {
        foreach ($types as $type) {
            if (isset($this->types[$type->key()])) {
                throw new InvalidArgumentException("El tipo de campo «{$type->key()}» está registrado dos veces.");
            }
            $this->types[$type->key()] = $type;
        }
    }

    public function get(string $key): FieldType
    {
        return $this->types[$key] ?? throw new InvalidArgumentException(
            "El tipo de campo «{$key}» no existe. Tipos disponibles: ".implode(', ', $this->keys()).'.',
        );
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->types);
    }

    /** @return array<string, FieldType> */
    public function all(): array
    {
        return $this->types;
    }
}
