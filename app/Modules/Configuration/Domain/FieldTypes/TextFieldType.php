<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Documents\Infrastructure\Rendering\WordRenderContext;
use PhpOffice\PhpWord\Element\AbstractContainer;

final class TextFieldType implements FieldType
{
    public const INSIDE = ['identification_card'];

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
        return ['inside' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::INSIDE)]];
    }

    public function normalizeConfig(array $config): array
    {
        $inside = $config['inside'] ?? null;

        return is_string($inside) && in_array($inside, self::INSIDE, true) ? ['inside' => $inside] : [];
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

    public function manual(): bool
    {
        return true;
    }

    public function teacherEditable(): bool
    {
        return true;
    }

    public function toSnapshot(mixed $value, array $config, array $context): mixed
    {
        return $value;
    }

    public function toWord(AbstractContainer $target, array $field, WordRenderContext $context): void
    {
        $value = $field['value'] ?? null;
        $context->writeBoxed($target, FreeText::isDocument($value) && ! FreeText::isEmpty($value) ? $value : null);
    }
}
