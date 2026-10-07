<?php

namespace App\Modules\Configuration\Domain\FieldTypes;

use App\Modules\Documents\Infrastructure\Rendering\WordRenderContext;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * Sección 6 del sílabo: una tabla por unidad (nombre, resultados de aprendizaje y una fila por
 * semana con horas ACD/APE/AA, actividades y evaluación) con el total de horas de cada unidad.
 * El docente agrega las unidades y las semanas; el total y la numeración se calculan solos.
 */
final class UnitsFieldType implements FieldType
{
    public const MAX_UNITS = 10;

    public const MAX_WEEKS = 16;

    private const HOURS = ['acd_h', 'ape_h', 'aa_h'];

    private const TEXTS = ['contenidos' => 2000, 'acd' => 2000, 'ape' => 2000, 'aa' => 2000, 'evaluacion' => 2000];

    private const FILL = 'D9E2F3';

    public function key(): string
    {
        return 'unidades';
    }

    public function label(): string
    {
        return 'Unidades de planificación';
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
        return [];
    }

    public function validateValue(mixed $value, array $config, string $attribute = 'value'): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($attribute, 'La planificación no tiene un formato válido.');
        }
        if (count($value) > self::MAX_UNITS) {
            $this->fail($attribute, 'La planificación admite hasta '.self::MAX_UNITS.' unidades.');
        }
        $units = [];
        $weeks = 0;
        foreach ($value as $unit) {
            if (! is_array($unit)) {
                $this->fail($attribute, 'Una unidad de la planificación no es válida.');
            }
            $rows = is_array($unit['semanas'] ?? null) && array_is_list($unit['semanas']) ? $unit['semanas'] : [];
            $weeks += count($rows);
            if ($weeks > self::MAX_WEEKS) {
                $this->fail($attribute, 'La planificación no puede pasar de '.self::MAX_WEEKS.' semanas en total.');
            }
            $units[] = [
                'nombre' => $this->text($unit['nombre'] ?? null, 300, $attribute),
                'resultado' => $this->text($unit['resultado'] ?? null, 1000, $attribute),
                'semanas' => array_map(fn (mixed $week): array => $this->week($week, $attribute), $rows),
            ];
        }

        return $units;
    }

    public function isEmpty(mixed $value, array $config): bool
    {
        if (! is_array($value)) {
            return true;
        }
        foreach ($value as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            foreach (['nombre', 'resultado'] as $key) {
                if (is_string($unit[$key] ?? null) && trim($unit[$key]) !== '') {
                    return false;
                }
            }
            foreach (is_array($unit['semanas'] ?? null) ? $unit['semanas'] : [] as $week) {
                foreach (is_array($week) ? $week : [] as $text) {
                    if (is_string($text) && trim($text) !== '') {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    public function manual(): bool
    {
        return false;
    }

    public function teacherEditable(): bool
    {
        return true;
    }

    public function toSnapshot(mixed $value, array $config, array $context): mixed
    {
        return is_array($value) ? $value : [];
    }

    public function toWord(AbstractContainer $target, array $field, WordRenderContext $context): void
    {
        $value = is_array($field['value'] ?? null) ? $field['value'] : [];
        $units = array_values(array_filter($value, 'is_array'));
        // Sin datos se dibuja una unidad en blanco: la hoja conserva el formato oficial.
        if ($units === []) {
            $units = [['nombre' => '', 'resultado' => '', 'semanas' => array_fill(0, 4, [])]];
        }
        $week = 0;
        foreach ($units as $index => $unit) {
            $context->writeDocument($target, ['type' => 'doc', 'content' => [$this->table($unit, $index + 1, $week), ['type' => 'paragraph']]]);
        }
    }

    /**
     * Tabla de una unidad con el diseño del formato oficial. `$week` lleva la numeración continua.
     *
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    public function table(array $unit, int $number, int &$week): array
    {
        $cell = fn (string $text, int $cols = 1, int $rows = 1, bool $head = false, ?string $align = null, bool $bold = false): array => [
            'type' => $head ? 'tableHeader' : 'tableCell',
            'attrs' => array_filter([
                'colspan' => $cols,
                'rowspan' => $rows,
                'backgroundColor' => $head ? '#'.self::FILL : null,
                'textAlign' => $align ?? ($head ? 'center' : null),
            ], fn (mixed $v): bool => $v !== null),
            'content' => [$text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text] + ($bold || $head ? ['marks' => [['type' => 'bold']]] : [])]]],
        ];
        $row = fn (array $cells): array => ['type' => 'tableRow', 'content' => $cells];
        $text = fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        $weeks = array_values(array_filter(is_array($unit['semanas'] ?? null) ? $unit['semanas'] : [], 'is_array'));

        $rows = [
            $row([$cell('Unidad No.', 1, 2, true), $cell((string) $number, 1, 2, true), $cell('Nombre de la unidad', 3, 1, true), $cell($text($unit['nombre'] ?? null), 4)]),
            $row([$cell('Resultados de aprendizaje', 3, 1, true), $cell($text($unit['resultado'] ?? null), 4)]),
            $row([$cell('Contenidos temáticos de la unidad', 1, 3, true), $cell('Semanas ('.self::MAX_WEEKS.')', 1, 3, true), $cell('Horas por semana — Organización del aprendizaje', 3, 1, true), $cell('ACTIVIDADES DE APRENDIZAJE', 3, 1, true), $cell('Evaluación de los aprendizajes', 1, 3, true)]),
            $row([$cell('DOCENCIA', 1, 1, true), $cell('ESTUDIANTE', 2, 1, true), $cell('Aprendizaje en contacto con el docente (ACD)', 1, 2, true), $cell('Aprendizaje práctico-experimental (APE)', 1, 2, true), $cell('Aprendizaje autónomo (AA)', 1, 2, true)]),
            $row([$cell('ACD', 1, 1, true), $cell('APE', 1, 1, true), $cell('AA', 1, 1, true)]),
        ];
        $totals = [0.0, 0.0, 0.0];
        foreach ($weeks as $item) {
            $week++;
            foreach (self::HOURS as $i => $key) {
                $totals[$i] += (float) str_replace(',', '.', $text($item[$key] ?? null));
            }
            $rows[] = $row([
                $cell($text($item['contenidos'] ?? null)), $cell((string) $week, 1, 1, false, 'center'),
                $cell($text($item['acd_h'] ?? null), 1, 1, false, 'center'), $cell($text($item['ape_h'] ?? null), 1, 1, false, 'center'), $cell($text($item['aa_h'] ?? null), 1, 1, false, 'center'),
                $cell($text($item['acd'] ?? null)), $cell($text($item['ape'] ?? null)), $cell($text($item['aa'] ?? null)), $cell($text($item['evaluacion'] ?? null)),
            ]);
        }
        $fmt = fn (float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        $rows[] = $row([$cell('Total, horas', 2, 1, true, 'right'), $cell($fmt($totals[0]), 1, 1, true), $cell($fmt($totals[1]), 1, 1, true), $cell($fmt($totals[2]), 1, 1, true), $cell('', 4, 1, true)]);

        return ['type' => 'table', 'content' => $rows];
    }

    /** @return array<string, string> */
    private function week(mixed $week, string $attribute): array
    {
        if (! is_array($week)) {
            $this->fail($attribute, 'Una semana de la planificación no es válida.');
        }
        $clean = [];
        foreach (self::TEXTS as $key => $max) {
            $clean[$key] = $this->text($week[$key] ?? null, $max, $attribute);
        }
        foreach (self::HOURS as $key) {
            $hours = $this->text($week[$key] ?? null, 6, $attribute);
            if ($hours !== '' && ! preg_match('/^\d{1,2}([.,]\d{1,2})?$/', $hours)) {
                $this->fail($attribute, 'Las horas deben ser un número, por ejemplo 2 o 1.5.');
            }
            $clean[$key] = $hours;
        }

        return $clean;
    }

    private function text(mixed $value, int $max, string $attribute): string
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value)) {
            $this->fail($attribute, 'La planificación contiene un dato que no es texto.');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            $this->fail($attribute, 'Un dato de la planificación es demasiado extenso.');
        }

        return $value;
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
