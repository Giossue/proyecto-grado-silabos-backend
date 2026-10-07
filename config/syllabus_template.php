<?php

return [
    /*
     * Plantilla oficial inicial (I-195): solo los tipos del registro de campos (texto, ficha, tabla, automatico y fijo).
     * En `fijo`, `content` es la clave del contenido institucional (FixedContent::official).
     * [clave de sección, título, [[clave del campo, etiqueta, tipo, configuración]]]
     * En `tabla`, `columns` y `rows` son [clave => etiqueta]; el provisionador los expande.
     */
    'baseline' => [
        ['identificacion', 'Identificación Institucional', [
            ['asignatura', '', 'automatico', ['source' => 'identification_card']],
            ['discapacidad_tipo', 'Estudiantes con discapacidad: Tipo de discapacidad', 'texto', []],
            ['discapacidad_adaptacion', 'Estudiantes con discapacidad: Breve descripción', 'texto', []],
            ['formacion_experiencia', 'Formación y experiencia académica – investigativa', 'texto', []],
        ]],
        ['descripcion', 'Descripción de la asignatura', [
            ['descripcion', '', 'ficha', ['adjustable' => false]],
        ]],
        ['objetivos', 'Objetivo(s) de la asignatura', [
            ['objetivo_general', 'Objetivo General', 'ficha', ['adjustable' => false]],
            ['objetivos_especificos', 'Objetivos específicos', 'ficha', ['adjustable' => false]],
        ]],
        ['resultados', 'Resultados de aprendizaje de la asignatura', [
            ['resultados_aprendizaje', '', 'ficha', ['adjustable' => false]],
        ]],
        ['habilidades', 'Habilidades Blandas de la asignatura', [
            ['habilidades_blandas', '', 'ficha', ['adjustable' => false]],
        ]],
        ['planificacion', 'Distribución y planificación de las unidades curriculares', [
            ['unidades', '', 'tabla', [
                'weeks' => 16,
                'columns' => [
                    'contenidos' => 'Contenidos temáticos de la unidad',
                    'acd_h' => 'ACD (h)',
                    'ape_h' => 'APE (h)',
                    'aa_h' => 'AA (h)',
                    'acd' => 'Aprendizaje en contacto con el docente (ACD)',
                    'ape' => 'Aprendizaje práctico-experimental (APE)',
                    'aa' => 'Aprendizaje autónomo (AA)',
                    'evaluacion' => 'Evaluación de los aprendizajes',
                ],
            ]],
        ]],
        ['metodologia', 'Metodología y Ambientes de Aprendizaje', [
            ['metodologia', '7.1 Métodos de Enseñanza – Aprendizaje', 'texto', []],
            ['tecnicas_ensenanza', '7.2 Técnicas de enseñanza', 'texto', []],
            ['recursos_medios', '7.3 Recursos y medios didácticos', 'texto', []],
            ['herramientas_tic', '7.4 Herramientas pedagógicas, Tecnologías de aprendizaje y conocimiento e Inteligencia Artificial en los procesos enseñanza-aprendizaje', 'texto', []],
            ['ambientes_aprendizaje', '7.5 Ambientes o escenarios de aprendizaje', 'texto', []],
        ]],
        ['evaluacion', 'EVALUACIÓN DE LOS APRENDIZAJES', [
            ['indicadores_evaluacion', '', 'fijo', ['content' => 'indicadores']],
            ['componentes_evaluacion', 'ESCALA DE VALORACIÓN', 'fijo', ['content' => 'escala']],
        ]],
        ['perfil_egreso', 'Relación de la asignatura con los resultados de aprendizaje del Perfil de egreso de la carrera', [
            ['contribucion_perfil', '', 'ficha', ['adjustable' => false]],
        ]],
        ['etica', 'Conducta y comportamiento ético', [
            ['conducta_etica', '', 'fijo', ['content' => 'etica']],
        ]],
        ['bibliografia', 'Bibliografía Básica', [
            ['bibliografia', '', 'ficha', ['adjustable' => true]],
            ['bibliografia_complementaria', 'Complementaria', 'ficha', ['adjustable' => true]],
        ]],
        ['revision', 'Revisión y aprobación', [
            ['firmas', '', 'fijo', ['content' => 'firmas']],
        ]],
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
