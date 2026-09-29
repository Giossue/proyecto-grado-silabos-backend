<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Syllabus\Application\IdentificationCard;

/** Adaptación de la ficha histórica; solo crea la propuesta, nunca escribe al leer. */
final class TemplateDocumentDefaults
{
    /** @return array<string, mixed> */
    public static function identification(): array
    {
        $data = IdentificationCard::sample();
        foreach (TemplateVariables::definitions([]) as $key => $definition) {
            if (! isset($definition['equals']) && str_starts_with($definition['source'], 'identification.')) {
                $data[substr($definition['source'], 15)] = '[[variable:'.$key.']]';
            }
        }
        $data['disability_type'] = '[[field:discapacidad_tipo]]';
        $data['disability_description'] = '[[field:discapacidad_adaptacion]]';
        $data['formation'] = '[[field:formacion_experiencia]]';
        $grid = IdentificationCard::grid($data);
        $labels = [
            'discapacidad_tiene' => ['Estudiantes con discapacidad', 'seleccion_unica'],
            'discapacidad_tipo' => ['Tipo de discapacidad', 'texto_corto'],
            'discapacidad_adaptacion' => ['Adaptación curricular', 'texto_corto'],
            'formacion_experiencia' => ['Formación y experiencia', 'texto_largo'],
        ];
        $rows = [];
        foreach ($grid as $r => $cells) {
            $row = ['type' => 'tableRow', 'attrs' => ['rowRole' => 'fixed'], 'content' => []];
            foreach ($cells as $c => $cell) {
                $cell['text'] = match (true) {
                    $r === 6 && $c === array_key_last($cells) => '[[variable:marca_unidad_basica]]',
                    $r === 7 && $c === array_key_last($cells) => '[[variable:marca_unidad_profesional]]',
                    $r === 8 && $c === array_key_last($cells) => '[[variable:marca_unidad_titulacion]]',
                    $r === 9 && $c === 2 => '[[field:discapacidad_tiene:Sí]]',
                    $r === 11 && $c === 1 => '[[field:discapacidad_tiene:No]]',
                    default => $cell['text'],
                };
                $marks = [['type' => 'textStyle', 'attrs' => ['color' => $cell['style'] === 'blue' ? '#FFFFFF' : '#000000']]];
                if ($cell['bold']) {
                    $marks[] = ['type' => 'bold'];
                }
                $paragraphs = [];
                foreach (explode("\n", $cell['text']) as $line) {
                    $content = [];
                    foreach (preg_split('/(\[\[(?:variable|field):[^\]]+\]\])/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                        if (preg_match('/^\[\[(variable|field):([^:\]]+)(?::([^\]]+))?\]\]$/', $part, $matches)) {
                            $key = $matches[2];
                            $attrs = $matches[1] === 'variable'
                                ? ['id' => $key, 'label' => $key]
                                : ['key' => $key, 'label' => $labels[$key][0], 'kind' => $labels[$key][1], 'choice' => $matches[3] ?? null];
                            $content[] = ['type' => $matches[1], 'attrs' => $attrs, 'marks' => $marks];
                        } else {
                            $content[] = ['type' => 'text', 'text' => $part, 'marks' => $marks];
                        }
                    }
                    $paragraphs[] = ['type' => 'paragraph', 'attrs' => ['textAlign' => $cell['center'] ? 'center' : 'left'], 'content' => $content];
                }
                $row['content'][] = ['type' => 'tableCell', 'attrs' => [
                    'colspan' => $cell['span'], 'rowspan' => $cell['rows'], 'colwidth' => $r === 0 ? array_map(fn ($weight) => (int) round(625 * $weight / array_sum(IdentificationCard::WIDTHS)), IdentificationCard::WIDTHS) : null,
                    'backgroundColor' => match ($cell['style']) {
                        'blue' => '#4F81BD', 'shade' => '#DBE5F1', default => null
                    },
                ], 'content' => $paragraphs];
            }
            $rows[] = $row;
        }

        return ['type' => 'doc', 'content' => [['type' => 'table', 'attrs' => ['repeatKey' => null], 'content' => $rows]]];
    }

    /** @return array<string, mixed> Texto libre recién creado (I-155): un párrafo que Administración escribe en la hoja. */
    public static function freeText(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Escriba aquí el texto.']]]]];
    }

    /** @return array<string, mixed> Contenido fijo recién creado: una celda que Administración reemplaza por su texto. */
    public static function fixedPlaceholder(): array
    {
        return self::fixedTable([[['Escriba aquí el contenido fijo.']]], 0);
    }

    /** @return array<string, mixed> Indicadores de evaluación UEB (puntos y ponderación por parcial). */
    public static function evaluationIndicators(): array
    {
        $rows = [
            ['Aprendizaje en contacto con el docente (ACD)', '2.5', '1.25'],
            ['Aprendizaje práctico-experimental (APE)', '2.0', '1.0'],
            ['Aprendizaje autónomo (AA)', '2.0', '1.0'],
            ['Evaluación parcial', '3.5', '1.75'],
            ['TOTAL', '10', '5'],
        ];

        return self::fixedTable([
            [['Indicadores', 1, 2], ['Primer parcial', 2], ['Segundo parcial', 2]],
            [['Puntos'], ['Ponderación'], ['Puntos'], ['Ponderación']],
            ...array_map(fn (array $row): array => [[$row[0]], [$row[1]], [$row[2]], [$row[1]], [$row[2]]], $rows),
        ], 2);
    }

    /** @return array<string, mixed> Escala de valoración UEB. */
    public static function gradingScale(): array
    {
        $rows = [
            ['A', '≥ 9', 'Excelente', 'Aprueba'],
            ['B', '< 9 y ≥ 8', 'Muy Bueno', 'Aprueba'],
            ['C', '< 8 y ≥ 7', 'Bueno', 'Aprueba'],
            ['D', '< 7 y ≥ 6', 'Regular', 'Recuperación'],
            ['E', '< 6', 'Deficiente', 'No aprueba'],
        ];

        return self::fixedTable([
            [['Escala cualitativa'], ['Escala cuantitativa grado y posgrado'], ['Equivalencias'], ['Valoración de la asignatura']],
            ...array_map(fn (array $row): array => array_map(fn (string $text): array => [$text], $row), $rows),
        ], 1);
    }

    /**
     * Revisión y aprobación: solo nombres y fechas. `fecha_entrega`, `nombre_coordinador`
     * y `fecha_revision` se llenan al exportar la revisión aprobada.
     *
     * @return array<string, mixed>
     */
    public static function signatures(): array
    {
        return self::fixedTable([
            [['Elaborado por'], ['Revisado y aprobado por']],
            [['[[variable:nombre_docente]]'], ['[[variable:nombre_coordinador]]']],
            [['Docente de la asignatura'], ['Coordinación de carrera']],
            [['Fecha de entrega: [[variable:fecha_entrega]]'], ['Fecha de revisión: [[variable:fecha_revision]]']],
        ], 1);
    }

    /**
     * Tabla sin campos del docente. Cada celda es [texto, colspan, rowspan]; el texto
     * admite `[[variable:clave]]`. Las primeras `$headerRows` filas van sombreadas.
     *
     * @param  list<list<array{0: string, 1?: int, 2?: int}>>  $rows
     * @return array<string, mixed>
     */
    private static function fixedTable(array $rows, int $headerRows): array
    {
        $content = [];
        foreach ($rows as $r => $cells) {
            $header = $r < $headerRows;
            $marks = $header ? [['type' => 'bold']] : [];
            $content[] = ['type' => 'tableRow', 'attrs' => ['rowRole' => 'fixed'], 'content' => array_map(function (array $cell) use ($header, $marks): array {
                $inline = [];
                foreach (preg_split('/(\[\[variable:[a-z0-9_]+\]\])/', $cell[0], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                    $inline[] = preg_match('/^\[\[variable:([a-z0-9_]+)\]\]$/', $part, $match)
                        ? ['type' => 'variable', 'attrs' => ['id' => $match[1], 'label' => $match[1]], 'marks' => $marks]
                        : ['type' => 'text', 'text' => $part, 'marks' => $marks];
                }

                return ['type' => 'tableCell', 'attrs' => [
                    'colspan' => $cell[1] ?? 1, 'rowspan' => $cell[2] ?? 1, 'colwidth' => null,
                    'backgroundColor' => $header ? '#DBE5F1' : null,
                ], 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => $header ? 'center' : 'left'], 'content' => $inline]]];
            }, $cells)];
        }

        return ['type' => 'doc', 'content' => [['type' => 'table', 'attrs' => ['repeatKey' => null], 'content' => $content]]];
    }
}
