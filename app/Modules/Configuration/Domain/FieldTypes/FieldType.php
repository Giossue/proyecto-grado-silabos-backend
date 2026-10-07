<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use App\Modules\Documents\Infrastructure\Rendering\WordRenderContext;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\AbstractContainer;

interface FieldType
{
    public function key(): string;

    public function label(): string;

    /** @return array<string, list<mixed>> */
    public function configRules(): array;

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function normalizeConfig(array $config): array;

    /** @param array<string, mixed> $config */
    public function defaultValue(array $config): mixed;

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ValidationException
     */
    public function validateValue(mixed $value, array $config, string $attribute = 'value'): mixed;

    /** @param array<string, mixed> $config */
    public function isEmpty(mixed $value, array $config): bool;

    public function teacherEditable(): bool;

    /**
     * Valor que se congela en la revisión (snapshot v7). Los tipos con datos vivos lo resuelven
     * aquí con `$context` (`identification`, `academic_context`); el resto devuelve el valor guardado.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    public function toSnapshot(mixed $value, array $config, array $context): mixed;

    /**
     * Pinta el campo del snapshot en el Word. Solo usa `$field` (etiqueta, `config`, `value`),
     * nunca datos vivos: la revisión enviada es inmutable.
     *
     * @param  array<string, mixed>  $field
     */
    public function toWord(AbstractContainer $target, array $field, WordRenderContext $context): void;
}
