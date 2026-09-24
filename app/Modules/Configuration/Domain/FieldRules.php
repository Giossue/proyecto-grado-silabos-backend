<?php

namespace App\Modules\Configuration\Domain;

use Illuminate\Validation\ValidationException;

/**
 * Límites que Administración fija a un campo del docente (I-138). Se comprueban al
 * enviar el sílabo; el borrador se guarda siempre para no perder trabajo.
 *
 * @phpstan-type Rules array{required: bool, max_length: int|null, min_rows: int|null, max_rows: int|null}
 */
final class FieldRules
{
    public const TEXT_TYPES = ['texto_corto', 'texto_largo'];

    public const MAX_LENGTH = 50000;

    public const MAX_ROWS = 500;

    /**
     * Forma canónica. Rechaza límites que no aplican al tipo del campo, para que la
     * configuración no prometa algo que nunca se valida.
     *
     * @return Rules
     */
    public static function normalize(mixed $raw, string $fieldType, string $errorKey = 'rules'): array
    {
        $raw = is_array($raw) ? $raw : [];
        $rules = [
            'required' => ! array_key_exists('required', $raw) || (bool) $raw['required'],
            'max_length' => self::integer($raw['max_length'] ?? null),
            'min_rows' => self::integer($raw['min_rows'] ?? null),
            'max_rows' => self::integer($raw['max_rows'] ?? null),
        ];
        $fail = static fn (string $message): never => throw ValidationException::withMessages([$errorKey => $message]);
        if ($rules['max_length'] !== null && ! in_array($fieldType, self::TEXT_TYPES, true)) {
            $fail('El máximo de caracteres solo aplica a campos de texto.');
        }
        if (($rules['min_rows'] !== null || $rules['max_rows'] !== null) && $fieldType !== 'repetible') {
            $fail('El mínimo y máximo de filas solo aplican a listas y tablas.');
        }
        if ($rules['max_length'] !== null && ($rules['max_length'] < 1 || $rules['max_length'] > self::MAX_LENGTH)) {
            $fail('El máximo de caracteres debe estar entre 1 y '.self::MAX_LENGTH.'.');
        }
        foreach (['min_rows' => 0, 'max_rows' => 1] as $key => $minimum) {
            if ($rules[$key] !== null && ($rules[$key] < $minimum || $rules[$key] > self::MAX_ROWS)) {
                $fail('Las filas deben estar entre '.$minimum.' y '.self::MAX_ROWS.'.');
            }
        }
        if ($rules['min_rows'] !== null && $rules['max_rows'] !== null && $rules['min_rows'] > $rules['max_rows']) {
            $fail('El mínimo de filas no puede superar al máximo.');
        }

        return $rules;
    }

    /**
     * Lectura tolerante de lo guardado: un valor que no aplica se ignora.
     *
     * @param  array<string, mixed>|null  $stored
     * @return Rules
     */
    public static function read(?array $stored, string $fieldType): array
    {
        $stored ??= [];
        $text = in_array($fieldType, self::TEXT_TYPES, true);
        $rows = $fieldType === 'repetible';

        return [
            'required' => ! array_key_exists('required', $stored) || (bool) $stored['required'],
            'max_length' => $text ? self::integer($stored['max_length'] ?? null) : null,
            'min_rows' => $rows ? self::integer($stored['min_rows'] ?? null) : null,
            'max_rows' => $rows ? self::integer($stored['max_rows'] ?? null) : null,
        ];
    }

    /**
     * Problemas de un campo con contenido. El vacío lo decide `required` aparte.
     *
     * @param  Rules  $rules
     * @return list<array{code: string, message: string}>
     */
    public static function issues(array $rules, string $label, mixed $value, int $rows): array
    {
        $issues = [];
        if ($rules['max_length'] !== null && is_string($value) && mb_strlen($value) > $rules['max_length']) {
            $issues[] = ['code' => 'campo_excede_caracteres', 'message' => "«{$label}» admite hasta {$rules['max_length']} caracteres; ahora tiene ".mb_strlen($value).'.'];
        }
        if ($rules['min_rows'] !== null && $rows < $rules['min_rows']) {
            $issues[] = ['code' => 'campo_pocas_filas', 'message' => "«{$label}» necesita al menos {$rules['min_rows']} fila(s); ahora tiene {$rows}."];
        }
        if ($rules['max_rows'] !== null && $rows > $rules['max_rows']) {
            $issues[] = ['code' => 'campo_demasiadas_filas', 'message' => "«{$label}» admite hasta {$rules['max_rows']} fila(s); ahora tiene {$rows}."];
        }

        return $issues;
    }

    private static function integer(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
