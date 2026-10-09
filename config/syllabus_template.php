<?php

use App\Modules\Configuration\Application\FixedContent;
use App\Modules\Configuration\Domain\FieldDocument;

$paragraph = static fn (string $text): array => $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
$variable = static fn (string $key): array => ['type' => 'variable', 'attrs' => ['id' => $key]];
$inline = static fn (array $content): array => ['type' => 'paragraph', 'content' => $content];
$cell = static fn (string|array $content, int $span = 1, int $rows = 1, bool $header = false, array $style = []): array => [
    'type' => $header ? 'tableHeader' : 'tableCell',
    'attrs' => ['colspan' => $span, 'rowspan' => $rows] + $style + ($header ? ['backgroundColor' => '#C3D4EA', 'bold' => true] : []),
    'content' => is_string($content) ? [$paragraph($content)] : $content,
];
$header = static fn (string $text, int $span = 1, int $rows = 1): array => $cell($text, $span, $rows, true);
$data = static fn (string $key, int $span = 1): array => $cell([$inline([$variable($key)])], $span);
$grid = static fn (array $rows): array => ['type' => 'table', 'content' => array_map(static fn (array $cells): array => ['type' => 'tableRow', 'content' => $cells], $rows)];
$doc = static fn (array $content): array => ['type' => 'doc', 'content' => $content];
$table = static fn (array $headers, array $rows): array => $doc([$grid([
    array_map($header, $headers),
    ...array_map(static fn (array $row): array => array_map($cell, $row), $rows),
])]);
$identification = $doc([$grid([
    [$cell([$inline([['type' => 'text', 'text' => 'FACULTAD: '], $variable('nombre_facultad')])], 9, 1, true, ['backgroundColor' => '#4F81BD', 'textColor' => '#FFFFFF', 'colwidth' => [144, 66, 38, 28, 50, 25, 109, 84, 81]])],
    [$header('CARRERA:', 1), $data('nombre_carrera', 4), $header('MODALIDAD DE ESTUDIO O APRENDIZAJE', 2), $data('modalidad_estudio', 2)],
    [$header('CAMPUS UNIVERSITARIO:', 2), $data('nombre_campus', 7)],
    [$header('ASIGNATURA', 2), $header('PERÍODO ACADÉMICO', 4), $header('CICLO'), $header('PARALELO'), $header('JORNADA')],
    [$data('nombre_asignatura', 2), $data('nombre_periodo_academico', 4), $data('ciclo_asignatura'), $data('codigo_paralelo'), $data('jornada_paralelo')],
    [$header('CÓDIGO:'), $data('codigo_asignatura'), $header('PRERREQUISITOS:', 3), $data('prerrequisitos_asignatura', 2), $header('CORREQUISITOS:'), $data('correquisitos_asignatura')],
    [$header('UNIDAD DE ORGANIZACIÓN CURRICULAR', 2, 3), $cell('Unidad Básica', 6), $data('marca_unidad_basica')],
    [$cell('Unidad Profesional', 6), $data('marca_unidad_profesional')],
    [$cell('Unidad de Titulación', 6), $data('marca_unidad_titulacion')],
    [$header('COMPONENTES DE APRENDIZAJE EN EL PERÍODO ACADÉMICO', 2, 4), $header('HORAS DEL PROFESOR', 3), $header('HORAS DEL ESTUDIANTE', 3), $header('TOTAL DE HORAS POR PERÍODO', 1, 2)],
    [$header('HORAS DE DOCENCIA (ACD)', 3), $header('HORAS DE PRÁCTICAS DE APLICACIÓN O EXPERIMENTACIÓN (APE)', 2), $header('HORAS DE APRENDIZAJE AUTÓNOMO (AA)')],
    [$data('horas_docencia', 3), $data('horas_practicas', 2), $data('horas_autonomas'), $data('horas_totales')],
    [$header('TOTAL, CRÉDITOS', 6), $data('creditos_asignatura')],
    [$header('NOMBRE DEL DOCENTE', 2), $data('nombre_docente', 5), $header('CORREO INSTITUCIONAL'), $data('correo_docente')],
])]);
$weeks = array_map(static fn (int $week): array => [$cell(''), $cell((string) $week), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell('')], range(1, 16));
$planning = $doc([
    $grid([
        [$header('Unidad No.:', 2, 2), $header('Nombre de la unidad', 2), $cell('', 5)],
        [$header('Resultados de aprendizaje', 2), $cell('', 5)],
    ]),
    $grid([
        [$header('Contenidos temáticos de la unidad', 1, 3), $header('Semanas (16)', 1, 3), $header('Horas por semana', 3), $header('ACTIVIDADES DE APRENDIZAJE', 3), $header('Evaluación de los aprendizajes', 1, 3)],
        [$header('Organización del aprendizaje', 3), $header('Aprendizaje en contacto con el docente (ACD)', 1, 2), $header('Aprendizaje práctico-experimental (APE)', 1, 2), $header('Aprendizaje autónomo (AA)', 1, 2)],
        [$header('ACD'), $header('APE'), $header('AA')],
        ...$weeks,
        [$cell('TOTAL', 2, 1, true, ['backgroundColor' => '#B8CCE4']), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell('')],
    ]),
]);
$graduateProfile = $doc([$grid([
    [$header('Resultados de aprendizaje de la asignatura', 1, 2), $header('Nivel de contribución (resultados de aprendizaje del perfil de egreso de la carrera)', 3), $header('Resultados de aprendizaje que aportan al perfil de egreso de la carrera', 1, 2)],
    [$header('ALTA (A)'), $header('MEDIA (M)'), $header('BAJA (B)')],
    [$cell(''), $cell(''), $cell(''), $cell(''), $cell('')],
])]);
$signatures = $doc([$grid([
    [$header('Elaborado por'), $header('Revisado y aprobado por'), $header('Bibliografía verificada por')],
    [$cell([$paragraph('Firma:'), $inline([$variable('firma_docente')])]), $cell([$paragraph('Firma:'), $inline([$variable('firma_coordinador')])]), $cell([$paragraph('Firma:'), $inline([$variable('firma_bibliotecario')])])],
    [$cell([$paragraph('Nombre:'), $inline([$variable('nombre_docente')]), $paragraph('Profesor – Investigador')]), $cell([$paragraph('Nombre:'), $inline([$variable('nombre_coordinador')]), $paragraph('Coordinador de Carrera')]), $cell([$paragraph('Nombre en la firma electrónica'), $paragraph('Bibliotecario')])],
    [$cell([$inline([['type' => 'text', 'text' => 'Fecha de entrega: '], $variable('fecha_entrega')])]), $cell([$inline([['type' => 'text', 'text' => 'Fecha de revisión: '], $variable('fecha_revision')])]), $cell('Fecha de verificación en la firma electrónica')],
])]);
$bibliography = $table(['Autor', 'Título', 'Año', 'Ciudad', 'Editorial', 'ISBN', 'Código'], [['', '', '', '', '', '', '']]);
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
        'habilidades' => ['title' => 'Habilidades blandas de la asignatura', 'summary' => 'Contenido de Coordinación por materia.', 'fields' => [
            ['habilidades_blandas', 'Habilidades blandas', 'coordinacion', $empty],
        ]],
        'planificacion' => ['title' => 'Distribución y planificación de las unidades curriculares', 'summary' => 'Tablas libres por unidad. Los totales los escribe el docente.', 'orientation' => 'landscape', 'fields' => [
            ['unidades', 'Unidades', 'docente', $planning],
        ]],
        'metodologia' => ['title' => 'Metodología y ambientes de aprendizaje', 'summary' => 'Contenido del docente.', 'fields' => [
            ['metodologia', '7.1 Métodos de enseñanza – aprendizaje', 'docente', $empty],
            ['tecnicas_ensenanza', '7.2 Técnicas de enseñanza', 'docente', $empty],
            ['recursos_medios', '7.3 Recursos y medios didácticos', 'docente', $empty],
            ['herramientas_tic', '7.4 Herramientas pedagógicas, tecnologías de aprendizaje y conocimiento e inteligencia artificial en los procesos de enseñanza-aprendizaje', 'docente', $empty],
            ['ambientes_aprendizaje', '7.5 Ambientes o escenarios de aprendizaje', 'docente', $empty],
        ]],
        'evaluacion' => ['title' => 'Evaluación de los aprendizajes', 'summary' => 'Indicadores y escala institucionales.', 'fields' => [
            ['indicadores_evaluacion', 'Indicadores por parcial', 'institucional', FixedContent::official('indicadores')],
            ['componentes_evaluacion', 'Escala de valoración', 'institucional', FixedContent::official('escala')],
        ]],
        'perfil_egreso' => ['title' => 'Relación de la asignatura con los resultados de aprendizaje del perfil de egreso de la carrera', 'summary' => 'Contenido de Coordinación por materia.', 'fields' => [
            ['contribucion_perfil', 'Resultados de la asignatura frente a los de la carrera', 'coordinacion', $graduateProfile],
        ]],
        'etica' => ['title' => 'Conducta y comportamiento ético', 'summary' => 'Texto institucional.', 'fields' => [
            ['conducta_etica', 'Conducta y comportamiento ético', 'institucional', FixedContent::official('etica')],
        ]],
        'bibliografia' => ['title' => 'Bibliografía', 'summary' => 'Bibliografía que escribe el docente.', 'fields' => [
            ['bibliografia', 'Básica', 'docente', $bibliography],
            ['bibliografia_complementaria', 'Complementaria', 'docente', $bibliography],
        ]],
        'revision' => ['title' => 'Revisión y aprobación', 'numbered' => false, 'summary' => 'Firmas, nombres y fechas.', 'fields' => [
            ['firmas', 'Revisión y aprobación', 'institucional', $signatures],
        ]],
    ],
    'baseline' => ['identificacion', 'descripcion', 'objetivos', 'resultados', 'habilidades', 'planificacion', 'metodologia', 'evaluacion', 'perfil_egreso', 'etica', 'bibliografia', 'revision'],
];
