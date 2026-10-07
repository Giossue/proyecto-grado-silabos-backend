<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Documents\Infrastructure\Rendering\WordRenderContext;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * Texto fijo: contenido institucional idéntico en todos los sílabos (evaluación, conducta ética,
 * firmas). Administración lo escribe una vez en la plantilla; el docente no lo edita y no se guarda
 * valor por sílabo. El contenido no puede quedar vacío: un campo fijo sin texto no aporta nada.
 */
final class FixedFieldType implements FieldType
{
    public function key(): string
    {
        return 'fijo';
    }

    public function label(): string
    {
        return 'Texto fijo';
    }

    public function configRules(): array
    {
        return ['content' => ['required', 'array']];
    }

    public function normalizeConfig(array $config): array
    {
        $content = FreeText::normalize($config['content'] ?? null, 'content');
        if (FreeText::isEmpty($content)) {
            throw ValidationException::withMessages(['content' => 'El texto fijo no puede estar vacío.']);
        }

        return ['content' => $content];
    }

    public function defaultValue(array $config): mixed
    {
        return $config['content'] ?? null;
    }

    public function validateValue(mixed $value, array $config, string $attribute = 'value'): mixed
    {
        if ($value !== null) {
            throw ValidationException::withMessages([$attribute => 'Un texto fijo no guarda valor.']);
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
        return FreeText::isDocument($config['content'] ?? null) ? $config['content'] : null;
    }

    public function toWord(AbstractContainer $target, array $field, WordRenderContext $context): void
    {
        $value = $field['value'] ?? null;
        $context->writeBoxed($target, FreeText::isDocument($value) && ! FreeText::isEmpty($value) ? $value : null);
    }
}
