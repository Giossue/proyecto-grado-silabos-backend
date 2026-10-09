<?php

use App\Modules\Configuration\Application\FixedContent;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Domain\FieldDocument;

$paragraph = static fn (string $text): array => $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
$cell = static fn (string $text, bool $header = false): array => ['type' => $header ? 'tableHeader' : 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [$paragraph($text)]];
$table = static fn (array $headers, array $rows): array => ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [
    ['type' => 'tableRow', 'content' => array_map(static fn (string $text): array => $cell($text, true), $headers)],
    ...array_map(static fn (array $row): array => ['type' => 'tableRow', 'content' => array_map($cell, $row)], $rows),
]]]];
$identification = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => array_map(
    static fn (array $row): array => ['type' => 'tableRow', 'content' => [
        $cell($row[0], true),
        ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => $row[1]]]]]]],
    ]],
    [
        ['Facultad', 'nombre_facultad'], ['Carrera', 'nombre_carrera'], ['Modalidad', 'modalidad_estudio'], ['Campus', 'nombre_campus'],
        ['Asignatura', 'nombre_asignatura'], ['Código', 'codigo_asignatura'], ['Período académico', 'nombre_periodo_academico'],
        ['Ciclo', 'ciclo_asignatura'], ['Paralelo', 'codigo_paralelo'], ['Jornada', 'jornada_paralelo'],
        ['Prerrequisitos', 'prerrequisitos_asignatura'], ['Correquisitos', 'correquisitos_asignatura'],
        ['Unidad de organización curricular', 'unidad_organizacion_curricular'],
        ['Horas ACD', 'horas_docencia'], ['Horas APE', 'horas_practicas'], ['Horas AA', 'horas_autonomas'],
        ['Total de horas', 'horas_totales'], ['Créditos', 'creditos_asignatura'], ['Docente', 'nombre_docente'], ['Correo', 'correo_docente'],
    ],
)]]];
$weeks = array_map(static fn (int $week): array => [(string) $week, '', '', '', '', '', '', '', ''], range(1, 16));
$planning = ['type' => 'doc', 'content' => [
    $paragraph('Unidad'), $paragraph('Nombre de la unidad:'), $paragraph('Resultados de aprendizaje:'),
    ...$table(['Semana', 'Contenidos', 'Horas ACD', 'Horas APE', 'Horas AA', 'Actividades ACD', 'Actividades APE', 'Actividades AA', 'Evaluación'], [...$weeks, ['TOTAL', '', '', '', '', '', '', '', '']])['content'],
]];
$empty = FieldDocument::empty();

return [
    'designs' => [
        'identificacion' => ['title' => 'Identificación institucional', 'summary' => 'Datos institucionales y contenido del docente.', 'fields' => [
            ['asignatura', 'Datos de identificación', 'institucional', $identification],
            ['discapacidad', 'Estudiantes con discapacidad', 'docente', $table(['Tipo de discapacidad', 'Breve descripción'], [['', '']])],
            ['formacion_experiencia', 'Formación y experiencia académica – investigativa', 'docente', $empty],
        ]],
        'descripcion' => ['title' => 'Descripción de la asignatura', 'summary' => 'Contenido de Coordinación por materia.', 'fields' => [
            ['descripcion', 'Descripción', 'coordinacion', $empty],
        ]],
        'objetivos' => ['title' => 'Objetivo(s) de la asignatura', 'summary' => 'Objetivo general y objetivos específicos.', 'fields' => [
            ['objetivo_general', 'Objetivo general', 'coordinacion', $empty],
            ['objetivos_especificos', 'Objetivos específicos', 'coordinacion', $empty],
        ]],
        'resultados' => ['title' => 'Resultados de aprendizaje', 'summary' => 'Resultados confirmados por Coordinación.', 'fields' => [
            ['resultados_aprendizaje', 'Resultados de aprendizaje', 'coordinacion', $empty],
        ]],
        'habilidades' => ['title' => 'Habilidades blandas', 'summary' => 'Contenido de Coordinación por materia.', 'fields' => [
            ['habilidades_blandas', 'Habilidades blandas', 'coordinacion', $empty],
        ]],
        'planificacion' => ['title' => 'Distribución y planificación de las unidades curriculares', 'summary' => 'Tablas libres por unidad. Los totales los escribe el docente.', 'orientation' => 'landscape', 'fields' => [
            ['unidades', 'Unidades', 'docente', $planning],
        ]],
        'metodologia' => ['title' => 'Metodología y ambientes de aprendizaje', 'summary' => 'Contenido del docente.', 'fields' => [
            ['metodologia', '7.1 Métodos', 'docente', $empty],
            ['tecnicas_ensenanza', '7.2 Técnicas', 'docente', $empty],
            ['recursos_medios', '7.3 Recursos', 'docente', $empty],
            ['herramientas_tic', '7.4 Herramientas', 'docente', $empty],
            ['ambientes_aprendizaje', '7.5 Ambientes', 'docente', $empty],
        ]],
        'evaluacion' => ['title' => 'Evaluación de los aprendizajes', 'summary' => 'Indicadores y escala institucionales.', 'fields' => [
            ['indicadores_evaluacion', 'Indicadores por parcial', 'institucional', FixedContent::official('indicadores')],
            ['componentes_evaluacion', 'Escala de valoración', 'institucional', FixedContent::official('escala')],
        ]],
        'perfil_egreso' => ['title' => 'Relación con el perfil de egreso', 'summary' => 'Contenido de Coordinación por materia.', 'fields' => [
            ['contribucion_perfil', 'Resultados de la asignatura frente a los de la carrera', 'coordinacion', $empty],
        ]],
        'etica' => ['title' => 'Conducta y comportamiento ético', 'summary' => 'Texto institucional.', 'fields' => [
            ['conducta_etica', 'Conducta y comportamiento ético', 'institucional', FixedContent::official('etica')],
        ]],
        'bibliografia' => ['title' => 'Bibliografía', 'summary' => 'Bibliografía que escribe el docente.', 'fields' => [
            ['bibliografia', 'Básica', 'docente', $table(['Código', 'Título', 'Autor', 'Año', 'Editorial', 'Ciudad', 'ISBN'], [['', '', '', '', '', '', '']])],
            ['bibliografia_complementaria', 'Complementaria', 'docente', $table(['Código', 'Título', 'Autor', 'Año', 'Editorial', 'Ciudad', 'ISBN'], [['', '', '', '', '', '', '']])],
        ]],
        'revision' => ['title' => 'Revisión y aprobación', 'summary' => 'Firmas, nombres y fechas.', 'fields' => [
            ['firmas', 'Revisión y aprobación', 'institucional', TemplateDocumentDefaults::signatures()],
        ]],
    ],
    'baseline' => ['identificacion', 'descripcion', 'objetivos', 'resultados', 'habilidades', 'planificacion', 'metodologia', 'evaluacion', 'perfil_egreso', 'etica', 'bibliografia', 'revision'],
];
