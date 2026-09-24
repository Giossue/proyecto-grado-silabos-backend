<?php

return [
    /*
     * Datos iniciales de la única plantilla institucional. Se mantienen fuera de
     * las acciones HTTP para que el formato inicial se pueda instalar con un seed
     * idempotente y no quede acoplado al controlador.
     */
    'baseline' => [
        ['identificacion', 'Identificación institucional', [
            ['asignatura', 'Identificación institucional', 'institutional', null],
        ]],
        ['descripcion', 'Descripción de la asignatura', [
            ['descripcion', 'Descripción de la asignatura', 'text', null],
        ]],
        ['objetivos', 'Objetivo(s) de la asignatura', [
            ['objetivo_general', 'Objetivo general', 'text', null],
            ['objetivos_especificos', 'Objetivos específicos', 'bulleted_list', null],
        ]],
        ['resultados', 'Resultados de aprendizaje de la asignatura', [
            ['resultados_aprendizaje', 'Resultados de aprendizaje', 'bulleted_list', null],
        ]],
        ['habilidades', 'Habilidades blandas de la asignatura', [
            ['habilidades_blandas', 'Habilidades blandas', 'text', null],
        ]],
        ['planificacion', 'Distribución y planificación de las unidades curriculares', [
            ['unidades', 'Planificación de unidades', 'table', 'planificacion'],
        ]],
        ['metodologia', 'Metodología y ambientes de aprendizaje', [
            ['metodologia', 'Métodos de enseñanza-aprendizaje', 'numbered_list', null],
            ['tecnicas_ensenanza', 'Técnicas de enseñanza', 'text', null],
            ['recursos_medios', 'Recursos y medios didácticos', 'bulleted_list', null],
            ['herramientas_tic', 'Herramientas pedagógicas, TAC e inteligencia artificial', 'bulleted_list', null],
            ['ambientes_aprendizaje', 'Ambientes o escenarios de aprendizaje', 'bulleted_list', null],
        ]],
        ['evaluacion', 'Evaluación de los aprendizajes', [
            // Contenido fijo: valores UEB que el docente no llena (I-138).
            ['indicadores_evaluacion', 'Indicadores de evaluación', 'fixed', 'indicadores'],
            ['componentes_evaluacion', 'Escala de valoración', 'fixed', 'escala'],
            ['recuperacion', 'Recuperación y aprobación', 'text', null],
        ]],
        ['perfil_egreso', 'Relación de la asignatura con los resultados de aprendizaje del perfil de egreso', [
            ['contribucion_perfil', 'Contribución al perfil de egreso', 'table', 'perfil'],
        ]],
        ['etica', 'Conducta y comportamiento ético', [
            ['conducta_etica', 'Conducta y comportamiento ético', 'text', null],
        ]],
        ['bibliografia', 'Bibliografía', [
            ['bibliografia', 'Bibliografía básica', 'table', 'bibliografia'],
            ['bibliografia_complementaria', 'Bibliografía complementaria', 'table', 'bibliografia'],
        ]],
        ['revision', 'Revisión y aprobación', [
            ['firmas', 'Revisión y aprobación', 'fixed', 'firmas'],
        ]],
    ],

    'identification_inputs' => [
        ['discapacidad_tiene', 'Estudiantes con discapacidad', 'seleccion_unica', ['Sí', 'No']],
        ['discapacidad_tipo', 'Tipo de discapacidad', 'texto_corto', null],
        ['discapacidad_adaptacion', 'Descripción de la adaptación curricular', 'texto_corto', null],
        ['formacion_experiencia', 'Formación y experiencia académica-investigativa', 'texto_largo', null],
    ],
];
