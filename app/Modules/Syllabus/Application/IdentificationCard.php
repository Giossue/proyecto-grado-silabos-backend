<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Domain\CurricularUnit;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Support\Collection;

/**
 * @phpstan-type Data array<string, string>
 * @phpstan-type Cell array{text: string, span: int, rows: int, style: string, bold: bool, small: bool, center: bool}
 */
final class IdentificationCard
{
    public const INPUT_KEYS = [
        'discapacidad_tiene' => 'disability',
        'discapacidad_tipo' => 'disability_type',
        'discapacidad_adaptacion' => 'disability_description',
        'formacion_experiencia' => 'formation',
    ];

    public const SHIFT_LABELS = ['matutina' => 'Matutina', 'vespertina' => 'Vespertina', 'nocturna' => 'Nocturna'];

    public const WIDTHS = [23.0, 10.5, 6.0, 4.5, 8.0, 4.0, 17.5, 13.5, 13.0];

    /**
     * @return Data
     */
    public static function fromSyllabus(Syllabus $syllabus): array
    {
        $syllabus->loadMissing(['parallel', 'teachers']);

        $data = self::build(
            $syllabus->contexto_academico ?? [],
            self::strings(collect([$syllabus->academicParallelCode()])),
            self::strings($syllabus->teachers->pluck('nombre')),
            self::strings($syllabus->teachers->pluck('correo_electronico')),
            ['formacion_experiencia' => implode("\n\n", self::strings($syllabus->teachers->pluck('formacion_experiencia')))],
        );
        $data['shift'] = implode(', ', array_map(
            fn (string $shift): string => self::SHIFT_LABELS[$shift] ?? ucfirst($shift),
            self::strings(collect([$syllabus->academicParallelShift()])),
        ));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $parallels
     * @param  list<string>  $teachers
     * @param  list<string>  $emails
     * @param  array<string, mixed>  $inputs  Valores escritos por el docente, por clave de campo.
     * @return Data
     */
    public static function build(array $context, array $parallels, array $teachers, array $emails, array $inputs = []): array
    {
        $get = fn (string $path): string => self::text(data_get($context, $path));
        $scheduled = fn (string $key): string => self::text(AcademicContextValues::scheduledSubject($context, $key));
        $input = fn (string $key): string => self::text($inputs[$key] ?? null);

        return [
            'faculty' => $get('career.faculty'),
            'career' => $get('career.name'),
            'modality' => $scheduled('modality'),
            'campus' => $scheduled('campus'),
            'subject' => $get('subject.name'),
            'period' => $scheduled('period'),
            'cycle' => self::cycle(data_get($context, 'subject.cycle')),
            'parallel' => self::join($parallels),
            'shift' => '',
            'code' => $get('subject.code'),
            'prerequisites' => self::join(data_get($context, 'subject.prerequisites')) ?: 'Ninguno',
            'corequisites' => self::join(data_get($context, 'subject.corequisites')) ?: 'Ninguno',
            'organization_unit' => $get('subject.organization_unit'),
            'hours_ac' => self::number($get('subject.hours_ac')),
            'hours_pae' => self::number($get('subject.hours_pae')),
            'hours_aa' => self::number($get('subject.hours_aa')),
            'total_hours' => self::number($get('subject.total_hours')),
            'credits' => self::number($get('subject.credits')),
            'teacher' => self::join($teachers),
            'email' => self::join($emails),

            'disability' => array_key_exists('discapacidad_tiene', $inputs)
                ? mb_strtolower(self::stripAccents($input('discapacidad_tiene')))
                : ($input('discapacidad_tipo') !== '' ? 'si' : 'no'),
            'disability_type' => $input('discapacidad_tipo'),
            'disability_description' => $input('discapacidad_adaptacion'),
            'formation' => $input('formacion_experiencia'),
        ];
    }

    /**
     * @return Data Valores de muestra para la hoja de la plantilla.
     */
    public static function sample(): array
    {
        $data = self::build(
            [
                'career' => ['name' => 'Software', 'faculty' => 'Ciencias Administrativas, Gestión Empresarial e Informática'],
                'subject' => [
                    'name' => 'Asignatura de ejemplo', 'code' => 'SW-P7-037', 'cycle' => 7, 'organization_unit' => 'Unidad profesional',
                    'prerequisites' => ['SW-P6-032'], 'corequisites' => [],
                    'hours_ac' => 32, 'hours_pae' => 16, 'hours_aa' => 48, 'total_hours' => 96, 'credits' => 2,
                ],
                'scheduled_subject' => ['period' => 'Marzo – Julio 2026', 'campus' => 'Matriz', 'modality' => 'Presencial'],
            ],
            ['A'],
            ['NOMBRE DEL DOCENTE'],
            ['docente@ueb.edu.ec'],
            [
                'discapacidad_tiene' => 'No',
                'formacion_experiencia' => 'Formación y experiencia en el área de la asignatura.',
            ],
        );
        $data['shift'] = 'Matutina';

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<Cell>>
     */
    public static function grid(array $data): array
    {
        $v = fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $unit = CurricularUnit::tryFromText($v('organization_unit'));
        $mark = fn (CurricularUnit $candidate): string => $unit === $candidate ? 'X' : '';
        $disability = $v('disability');

        $label = fn (string $text, int $span = 1, int $rows = 1, bool $shade = false): array => self::cell($text, $span, $rows, $shade ? 'shade' : 'plain', true, false, false);
        $value = fn (string $text, int $span = 1, int $rows = 1, bool $shade = false): array => self::cell($text, $span, $rows, $shade ? 'shade' : 'plain', false, false, false);
        $small = fn (string $text, int $span = 1, int $rows = 1, bool $shade = false): array => self::cell($text, $span, $rows, $shade ? 'shade' : 'plain', true, true, true);
        $number = fn (string $text, int $span = 1, bool $shade = false): array => self::cell($text, $span, 1, $shade ? 'shade' : 'plain', true, false, true);
        $note = fn (string $title, string $text, int $span): array => self::cell(trim($title.' '.$text), $span, 1, 'plain', false, true, false);

        return [
            [self::cell('FACULTAD: '.$v('faculty'), 9, 1, 'blue', true, false, false)],
            [$label('CARRERA:', 1, 1, true), $value($v('career'), 4, 1, true), $label('MODALIDAD DE ESTUDIO O APRENDIZAJE', 2, 1, true), $value($v('modality'), 2, 1, true)],
            [$label('CAMPUS UNIVERSITARIO:', 2), $value($v('campus'), 7)],
            [$label('ASIGNATURA', 2, 1, true), $label('PERIODO ACADÉMICO', 4, 1, true), $label('CICLO', 1, 1, true), $label('PARALELO', 1, 1, true), $label('JORNADA', 1, 1, true)],
            [$value($v('subject'), 2), $value($v('period'), 4), $value($v('cycle')), $value($v('parallel')), $value($v('shift'))],
            [$label('CÓDIGO:', 1, 1, true), $value($v('code'), 1, 1, true), $label('PRERREQUISITOS:', 3, 1, true), $value($v('prerequisites'), 2, 1, true), $label('CORREQUISITOS:', 1, 1, true), $value($v('corequisites'), 1, 1, true)],
            [$label('UNIDAD DE ORGANIZACIÓN CURRICULAR', 2, 3), $value('Unidad Básica', 6), $number($mark(CurricularUnit::Basica))],
            [$value('Unidad Profesional', 6, 1, true), $number($mark(CurricularUnit::Profesional), 1, true)],
            [$value('Unidad de Titulación', 6), $number($mark(CurricularUnit::IntegracionCurricular))],
            [$label('ESTUDIANTES CON DISCAPACIDAD', 2, 3), $value('Sí', 1, 2), self::cell($disability === 'si' ? 'X' : '', 1, 2, 'plain', true, false, true), $note('Tipo de discapacidad:', $v('disability_type'), 5)],
            [$note('Breve descripción:', $v('disability_description'), 5)],
            [$value('No'), $number($disability === 'no' ? 'X' : ''), $value('', 5)],
            [$label('COMPONENTES DE APRENDIZAJE EN EL PERÍODO ACADÉMICO', 2, 4), $small('HORAS DEL PROFESOR', 3), $small('HORAS DEL ESTUDIANTE', 3), $small('TOTAL DE HORAS POR PERÍODO', 1, 2)],
            [$small('HORAS DE DOCENCIA (ACD)', 3, 1, true), $small('HORAS DE PRÁCTICAS DE APLICACIÓN O EXPERIMENTACIÓN (APE)', 2, 1, true), $small('HORAS DE APRENDIZAJE AUTÓNOMAS (AA)', 1, 1, true)],
            [$number(self::number($v('hours_ac')), 3), $number(self::number($v('hours_pae')), 2), $number(self::number($v('hours_aa'))), $number(self::number($v('total_hours')))],
            [$small('TOTAL, CRÉDITOS', 6, 1, true), $number(self::number($v('credits')), 1, true)],
            [$label('NOMBRE DEL DOCENTE', 2), $value($v('teacher'), 5), $small('CORREO INSTITUCIONAL'), $value($v('email'))],
            [self::cell("FORMACIÓN Y EXPERIENCIA ACADÉMICA – INVESTIGATIVA:\n".$v('formation'), 9, 1, 'plain', false, false, false)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function document(array $data): array
    {
        $total = array_sum(self::WIDTHS);
        $rows = [];
        foreach (self::grid($data) as $r => $cells) {
            $row = ['type' => 'tableRow', 'attrs' => ['rowRole' => 'fixed'], 'content' => []];
            foreach ($cells as $cell) {
                $marks = [['type' => 'textStyle', 'attrs' => ['color' => $cell['style'] === 'blue' ? '#FFFFFF' : '#000000']]];
                if ($cell['bold']) {
                    $marks[] = ['type' => 'bold'];
                }
                $paragraphs = [];
                foreach (explode("\n", $cell['text']) as $line) {
                    $paragraphs[] = ['type' => 'paragraph', 'attrs' => ['textAlign' => $cell['center'] ? 'center' : 'left'], 'content' => $line === '' ? [] : [['type' => 'text', 'text' => $line, 'marks' => $marks]]];
                }
                $row['content'][] = ['type' => 'tableCell', 'attrs' => [
                    'colspan' => $cell['span'], 'rowspan' => $cell['rows'],
                    'colwidth' => $r === 0 ? array_map(fn (float $weight): int => (int) round(625 * $weight / $total), self::WIDTHS) : null,
                    'backgroundColor' => match ($cell['style']) {
                        'blue' => '#4F81BD', 'shade' => '#C3D4EA', default => null
                    },
                ], 'content' => $paragraphs];
            }
            $rows[] = $row;
        }

        return ['type' => 'doc', 'content' => [['type' => 'table', 'content' => $rows]]];
    }

    /**
     * @return Cell
     */
    private static function cell(string $text, int $span, int $rows, string $style, bool $bold, bool $small, bool $center): array
    {
        return ['text' => $text, 'span' => $span, 'rows' => $rows, 'style' => $style, 'bold' => $bold, 'small' => $small, 'center' => $center];
    }

    private static function cycle(mixed $value): string
    {
        $names = [1 => 'Primero', 'Segundo', 'Tercero', 'Cuarto', 'Quinto', 'Sexto', 'Séptimo', 'Octavo', 'Noveno', 'Décimo'];

        return is_numeric($value) && isset($names[(int) $value]) ? $names[(int) $value] : self::text($value);
    }

    public static function number(string $value): string
    {
        $value = trim($value);
        if (! is_numeric($value) || ! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    private static function text(mixed $value): string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private static function join(mixed $values): string
    {
        if (! is_array($values)) {
            return self::text($values);
        }
        $parts = [];
        foreach ($values as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $parts[] = trim((string) $item);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<string>
     */
    private static function strings(Collection $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '' && ! in_array($value, $strings, true)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    private static function stripAccents(string $value): string
    {
        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    }
}
