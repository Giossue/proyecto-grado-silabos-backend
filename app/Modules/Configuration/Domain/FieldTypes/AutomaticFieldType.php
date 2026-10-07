<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use App\Modules\Documents\Infrastructure\Rendering\WordRenderContext;
use App\Modules\Syllabus\Application\IdentificationCard;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\AbstractContainer;

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

    public function manual(): bool
    {
        return false;
    }

    public function teacherEditable(): bool
    {
        return false;
    }

    public function toSnapshot(mixed $value, array $config, array $context): mixed
    {
        return match ($config['source'] ?? null) {
            'identification_card' => is_array($context['identification'] ?? null) ? $context['identification'] : null,
            default => null,
        };
    }

    public function toWord(AbstractContainer $target, array $field, WordRenderContext $context): void
    {
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $value = is_array($field['value'] ?? null) ? $field['value'] : null;
        if (($config['source'] ?? null) === 'identification_card' && $value !== null) {
            $context->writeDocument($target, IdentificationCard::document($value));

            return;
        }
        $context->writeDocument($target, null);
    }
}
