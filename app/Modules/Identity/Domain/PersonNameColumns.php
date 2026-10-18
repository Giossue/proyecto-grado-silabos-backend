<?php

namespace App\Modules\Identity\Domain;

final class PersonNameColumns
{
    public const COLUMNS = [
        'primer_nombre' => 'Primer nombre',
        'segundo_nombre' => 'Segundo nombre',
        'primer_apellido' => 'Primer apellido',
        'segundo_apellido' => 'Segundo apellido',
    ];

    public const NOTES = [
        'Una fila por persona; no deje filas vacías entre registros.',
        'Primer nombre y primer apellido son obligatorios; el segundo nombre y el segundo apellido son opcionales.',
        'Escriba cada nombre o apellido en su propia columna, sin abreviaturas.',
    ];

    public const TEACHER_NOTES = [
        'Una fila por docente; no deje filas vacías entre registros.',
        'Todas las columnas son obligatorias: primer nombre, segundo nombre, primer apellido, segundo apellido y correo.',
        'Escriba cada nombre o apellido en su propia columna, sin abreviaturas.',
    ];

    /**
     * @param  array<string, string>  $values
     * @param  bool  $requireAll  exige también el segundo nombre y el segundo apellido
     * @return array{0: array<string, string>, 1: list<string>} valores con `nombre` compuesto y errores
     */
    public static function compose(array $values, bool $requireAll = false): array
    {
        $errors = [];
        if ($requireAll && $values['segundo_nombre'] === '') {
            $errors[] = 'El segundo nombre es obligatorio.';
        }
        if ($requireAll && $values['segundo_apellido'] === '') {
            $errors[] = 'El segundo apellido es obligatorio.';
        }
        if ($values['primer_nombre'] === '') {
            $errors[] = 'El primer nombre es obligatorio.';
        }
        if ($values['primer_apellido'] === '') {
            $errors[] = 'El primer apellido es obligatorio.';
        }
        $name = PersonName::normalize(implode(' ', array_filter([
            $values['primer_nombre'], $values['segundo_nombre'], $values['primer_apellido'], $values['segundo_apellido'],
        ], fn (string $part): bool => $part !== '')));
        $rest = array_diff_key($values, self::COLUMNS);

        return [['nombre' => $name, ...$rest], $errors];
    }
}
