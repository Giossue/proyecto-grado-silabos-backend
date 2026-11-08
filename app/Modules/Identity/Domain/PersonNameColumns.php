<?php

namespace App\Modules\Identity\Domain;

final class PersonNameColumns
{
    public const COLUMNS = [
        'nombres' => 'Nombres',
        'apellidos' => 'Apellidos',
    ];

    /** Cómo se nombra cada columna en los mensajes: «Los nombres son obligatorios». */
    private const SUBJECT = [
        'nombres' => 'Los nombres',
        'apellidos' => 'Los apellidos',
    ];

    public const MAX_LENGTH = 90;

    public const NOTES = [
        'Una fila por persona; no deje filas vacías entre registros.',
        'Nombres y apellidos son obligatorios: escriba todos los nombres en «Nombres» y todos los apellidos en «Apellidos».',
        'Use solo letras, espacios, apóstrofo o guion, sin abreviaturas.',
    ];

    public const TEACHER_NOTES = [
        'Una fila por docente; no deje filas vacías entre registros.',
        'Todas las columnas son obligatorias: nombres, apellidos y correo.',
        'Escriba todos los nombres en «Nombres» y todos los apellidos en «Apellidos», solo con letras, espacios, apóstrofo o guion.',
    ];

    /** @return array<string, string> mensajes de validación de los formularios, por regla */
    public static function messages(): array
    {
        $messages = [];
        foreach (self::SUBJECT as $key => $subject) {
            $messages["{$key}.required"] = self::requiredMessage($key);
            $messages["{$key}.max"] = self::lengthMessage($key);
            $messages["{$key}.regex"] = self::lettersMessage($key);
        }

        return $messages;
    }

    /**
     * @param  array<string, string>  $values
     * @return array{0: array<string, string>, 1: list<string>} valores con `nombre` compuesto y errores
     */
    public static function compose(array $values): array
    {
        $errors = [];
        foreach (array_keys(self::COLUMNS) as $key) {
            $value = PersonName::collapse($values[$key] ?? '');
            if ($value === '') {
                $errors[] = self::requiredMessage($key);
            } elseif (mb_strlen($value) > self::MAX_LENGTH) {
                $errors[] = self::lengthMessage($key);
            } elseif (! PersonName::isValidPart($value)) {
                $errors[] = self::lettersMessage($key);
            }
        }
        $rest = array_diff_key($values, self::COLUMNS);

        return [['nombre' => self::fullName($values['nombres'] ?? '', $values['apellidos'] ?? ''), ...$rest], $errors];
    }

    public static function fullName(string $names, string $surnames): string
    {
        return PersonName::normalize($names.' '.$surnames);
    }

    private static function requiredMessage(string $key): string
    {
        return self::SUBJECT[$key].' son obligatorios.';
    }

    private static function lengthMessage(string $key): string
    {
        return self::SUBJECT[$key].' admiten hasta '.self::MAX_LENGTH.' caracteres.';
    }

    private static function lettersMessage(string $key): string
    {
        return self::SUBJECT[$key].' solo admiten letras, espacios, apóstrofo y guion.';
    }
}
