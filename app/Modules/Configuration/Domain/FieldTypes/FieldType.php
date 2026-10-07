<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use Illuminate\Validation\ValidationException;

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
}
