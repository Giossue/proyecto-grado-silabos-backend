<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use Illuminate\Validation\ValidationException;

final class AutomaticFieldType implements FieldType
{
    public const SOURCES = ['identification_card'];

    public function key(): string
    {
        return 'automatico';
    }

    public function label(): string
    {
        return 'Dato automático';
    }

    public function configRules(): array
    {
        return ['source' => ['required', 'string', 'in:'.implode(',', self::SOURCES)]];
    }

    public function normalizeConfig(array $config): array
    {
        $source = $config['source'] ?? null;
        if (! is_string($source) || ! in_array($source, self::SOURCES, true)) {
            throw ValidationException::withMessages(['source' => 'La fuente del dato automático no existe.']);
        }

        return ['source' => $source];
    }

    public function defaultValue(array $config): mixed
    {
        return null;
    }

    public function validateValue(mixed $value, array $config, string $attribute = 'value'): mixed
    {
        if ($value !== null) {
            throw ValidationException::withMessages([$attribute => 'Un dato automático no guarda valor.']);
        }

        return null;
    }

    public function isEmpty(mixed $value, array $config): bool
    {
        return false;
    }

    public function teacherEditable(): bool
    {
        return false;
    }
}
