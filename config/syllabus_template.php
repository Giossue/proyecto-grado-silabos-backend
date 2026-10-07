<?php

return [
    'designs' => [
        'identificacion' => [
            'title' => 'Identificación Institucional',
            'summary' => 'Tabla oficial con los datos de la asignatura, el periodo y el docente. El docente completa dentro de ella la discapacidad y su formación.',
            'fields' => [
                ['asignatura', '', 'automatico', ['source' => 'identification_card']],
                ['discapacidad_tipo', 'Estudiantes con discapacidad: Tipo de discapacidad', 'texto', ['inside' => 'identification_card', 'optional' => true]],
                ['discapacidad_adaptacion', 'Estudiantes con discapacidad: Breve descripción', 'texto', ['inside' => 'identification_card', 'optional' => true]],
                ['formacion_experiencia', 'Formación y experiencia académica – investigativa', 'texto', ['inside' => 'identification_card', 'from' => 'perfil_docente']],
            ],
        ],
        'descripcion' => [
            'title' => 'Descripción de la asignatura',
            'summary' => 'Texto que Coordinación escribe una vez por asignatura en su ficha.',
            'fields' => [
                ['descripcion', '', 'ficha', ['adjustable' => false]],
            ],
        ],
        'objetivos' => [
            'title' => 'Objetivo(s) de la asignatura',
            'summary' => 'Objetivo general y objetivos específicos, desde la ficha de la asignatura.',
            'fields' => [
                ['objetivo_general', 'Objetivo General', 'ficha', ['adjustable' => false]],
                ['objetivos_especificos', 'Objetivos específicos', 'ficha', ['adjustable' => false]],
            ],
        ],
        'resultados' => [
            'title' => 'Resultados de aprendizaje de la asignatura',
            'summary' => 'Lista de resultados de aprendizaje, desde la ficha de la asignatura.',
            'fields' => [
                ['resultados_aprendizaje', '', 'ficha', ['adjustable' => false]],
            ],
        ],
        'habilidades' => [
            'title' => 'Habilidades Blandas de la asignatura',
            'summary' => 'Habilidades blandas con su definición, desde la ficha de la asignatura.',
            'fields' => [
                ['habilidades_blandas', '', 'ficha', ['adjustable' => false]],
            ],
        ],
        'planificacion' => [
            'orientation' => 'landscape',
            'title' => 'Distribución y planificación de las unidades curriculares',
            'summary' => 'Una tabla por unidad (nombre, resultados, semanas con horas, actividades y evaluación, y total de horas); la llena el docente.',
            'fields' => [
                ['unidades', '', 'unidades', []],
            ],
        ],
        'metodologia' => [
            'title' => 'Metodología y Ambientes de Aprendizaje',
            'summary' => 'Cinco textos que escribe el docente: métodos, técnicas, recursos, herramientas TIC, TAC e IA, y ambientes.',
            'fields' => [
                ['metodologia', '7.1 Métodos de Enseñanza – Aprendizaje', 'texto', []],
                ['tecnicas_ensenanza', '7.2 Técnicas de enseñanza', 'texto', []],
                ['recursos_medios', '7.3 Recursos y medios didácticos', 'texto', []],
                ['herramientas_tic', '7.4 Herramientas pedagógicas, Tecnologías de aprendizaje y conocimiento e Inteligencia Artificial en los procesos enseñanza-aprendizaje', 'texto', []],
                ['ambientes_aprendizaje', '7.5 Ambientes o escenarios de aprendizaje', 'texto', []],
            ],
        ],
        'evaluacion' => [
            'title' => 'EVALUACIÓN DE LOS APRENDIZAJES',
            'summary' => 'Indicadores por parcial y escala de valoración de la UEB; igual en todos los sílabos.',
            'fields' => [
                ['indicadores_evaluacion', '', 'fijo', ['content' => 'indicadores']],
                ['componentes_evaluacion', 'ESCALA DE VALORACIÓN', 'fijo', ['content' => 'escala']],
            ],
        ],
        'perfil_egreso' => [
            'title' => 'Relación de la asignatura con los resultados de aprendizaje del Perfil de egreso de la carrera',
            'summary' => 'Contribución de la asignatura al perfil de egreso, desde la ficha de la asignatura.',
            'fields' => [
                ['contribucion_perfil', '', 'ficha', ['adjustable' => false]],
            ],
        ],
        'etica' => [
            'title' => 'Conducta y comportamiento ético',
            'summary' => 'Compromisos y estándares de comportamiento de la UEB; igual en todos los sílabos.',
            'fields' => [
                ['conducta_etica', '', 'fijo', ['content' => 'etica']],
            ],
        ],
        'bibliografia' => [
            'title' => 'Bibliografía Básica',
            'summary' => 'Bibliografía básica y complementaria, desde la ficha; el docente puede ajustarlas.',
            'fields' => [
                ['bibliografia', '', 'ficha', ['adjustable' => true]],
                ['bibliografia_complementaria', 'Complementaria', 'ficha', ['adjustable' => true]],
            ],
        ],
        'revision' => [
            'title' => 'Revisión y aprobación',
            'summary' => 'Cuadro de firmas del docente y de la coordinación con sus fechas; igual en todos los sílabos.',
            'fields' => [
                ['firmas', '', 'fijo', ['content' => 'firmas']],
            ],
        ],
    ],

    'baseline' => [
        'identificacion', 'descripcion', 'objetivos', 'resultados', 'habilidades', 'planificacion',
        'metodologia', 'evaluacion', 'perfil_egreso', 'etica', 'bibliografia', 'revision',
    ],

    /* Solo los usa la API anterior de plantilla (se borra en la etapa 8 de I-195). */
    'identification_inputs' => [
        ['discapacidad_tiene', 'Estudiantes con discapacidad', 'seleccion_unica', ['Sí', 'No'], null],
        ['discapacidad_tipo', 'Tipo de discapacidad', 'texto_corto', null, ['key' => 'discapacidad_tiene', 'value' => 'Sí']],
        ['discapacidad_adaptacion', 'Descripción de la adaptación curricular', 'texto_corto', null, ['key' => 'discapacidad_tiene', 'value' => 'Sí']],
        ['formacion_experiencia', 'Formación y experiencia académica-investigativa', 'texto_largo', null, null],
    ],

    /*
     * Catálogo de plantillas de campo (I-190): diseños oficiales que Administración elige al agregar
     * un campo y luego puede modificar. `source`: «ficha» se llena desde la ficha de la asignatura
     * (con `adjust`, el docente puede ajustarlo); `initial`: contenido que trae el campo en cada sílabo nuevo.
     * [clave, título, resumen, clave del campo, origen, ajustable por el docente, contenido inicial]
     */
    'catalog' => [
        ['identificacion', 'Identificación institucional', 'Tabla inicial con los datos de la asignatura, el periodo y el docente; no se edita.', 'asignatura', null, false, null],
        ['descripcion', 'Descripción de la asignatura', 'Texto que Coordinación escribe una vez por materia.', 'descripcion', 'ficha', false, null],
        ['objetivo_general', 'Objetivo general', 'Se llena desde la ficha de la asignatura.', 'objetivo_general', 'ficha', false, null],
        ['objetivos_especificos', 'Objetivos específicos', 'Se llena desde la ficha de la asignatura.', 'objetivos_especificos', 'ficha', false, null],
        ['resultados_aprendizaje', 'Resultados de aprendizaje', 'Se llena desde la ficha de la asignatura.', 'resultados_aprendizaje', 'ficha', false, null],
        ['habilidades_blandas', 'Habilidades blandas', 'Se llena desde la ficha de la asignatura.', 'habilidades_blandas', 'ficha', false, null],
        ['distribucion', 'Distribución y planificación (16 semanas)', 'Tabla de 16 semanas con horas, actividades y evaluación; el docente la llena.', 'unidades', null, false, 'distribucion'],
        ['metodologia', 'Metodología y ambientes de aprendizaje', 'Texto libre que escribe el docente.', 'metodologia', null, false, null],
        ['bibliografia_basica', 'Bibliografía básica', 'Llega de la ficha; el docente puede agregar o quitar libros.', 'bibliografia', 'ficha', true, null],
        ['bibliografia_complementaria', 'Bibliografía complementaria', 'Llega de la ficha; el docente puede agregar o quitar libros.', 'bibliografia_complementaria', 'ficha', true, null],
        ['perfil_egreso', 'Contribución al perfil de egreso', 'Se llena desde la ficha de la asignatura.', 'contribucion_perfil', 'ficha', false, null],
    ],
];
