<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use App\Modules\Configuration\Domain\FreeText;

final class ProfileFieldType implements FieldType
{
    public function key(): string
    {
        return 'ficha';
    }

    public function label(): string
    {
        return 'Ficha de la asignatura';
    }

    public function configRules(): array
    {
        return ['adjustable' => ['sometimes', 'boolean']];
    }

    public function normalizeConfig(array $config): array
    {
        return ['adjustable' => filter_var($config['adjustable'] ?? false, FILTER_VALIDATE_BOOLEAN)];
    }

    public function defaultValue(array $config): mixed
    {
        return FreeText::fromText('');
    }

    public function validateValue(mixed $value, array $config, string $attribute = 'value'): mixed
    {
        return FreeText::normalize($value, $attribute);
    }

    public function isEmpty(mixed $value, array $config): bool
    {
        return ! FreeText::isDocument($value) || FreeText::isEmpty($value);
    }

    public function teacherEditable(): bool
    {
        return false;
    }
}
