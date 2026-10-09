<?php

namespace App\Modules\Configuration\Application;

final class TemplateDocumentDefaults
{
    /**
     * @return array<string, mixed> Indicadores de evaluación UEB (puntos y ponderación por parcial).
     */
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

    /**
     * @return array<string, mixed> Escala de valoración UEB.
     */
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
     * @return array<string, mixed>
     */
    public static function signatures(): array
    {
        return self::fixedTable([
            [['Elaborado por'], ['Revisado y aprobado por'], ['Bibliografía verificada por']],
            [['[[variable:firma_docente]]'], ['[[variable:firma_coordinador]]'], ['[[variable:firma_bibliotecario]]']],
            [['[[variable:nombre_docente]]'], ['[[variable:nombre_coordinador]]'], ['Nombre en la firma electrónica']],
            [['Docente de la asignatura'], ['Coordinación de carrera'], ['Biblioteca']],
            [['Fecha de entrega: [[variable:fecha_entrega]]'], ['Fecha de aprobación: [[variable:fecha_revision]]'], ['Fecha en la firma electrónica']],
        ], 1);
    }

    /**
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
                    'backgroundColor' => $header ? '#C3D4EA' : null,
                ], 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => $header ? 'center' : 'left'], 'content' => $inline]]];
            }, $cells)];
        }

        return ['type' => 'doc', 'content' => [['type' => 'table', 'attrs' => ['repeatKey' => null], 'content' => $content]]];
    }
}
