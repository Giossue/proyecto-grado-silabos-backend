<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use App\Modules\Configuration\Domain\FreeText;

final class TextFieldType implements FieldType
{
    public function key(): string
    {
        return 'texto';
    }

    public function label(): string
    {
        return 'Texto';
    }

    public function configRules(): array
    {
        return [];
    }

    public function normalizeConfig(array $config): array
    {
        return [];
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
        return true;
    }
}
