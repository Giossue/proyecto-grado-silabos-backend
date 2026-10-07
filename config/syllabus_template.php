<?php

return [
    /*
     * Plantilla oficial inicial (I-195): solo los cuatro tipos del registro de campos.
     * [clave de sección, título, [[clave del campo, etiqueta, tipo, configuración]]]
     * En `tabla`, `columns` y `rows` son [clave => etiqueta]; el provisionador los expande.
     */
    'baseline' => [
        ['identificacion', 'Identificación institucional', [
            ['asignatura', 'Identificación institucional', 'automatico', ['source' => 'identification_card']],
            ['discapacidad_tipo', 'Tipo de discapacidad', 'texto', []],
            ['discapacidad_adaptacion', 'Descripción de la adaptación curricular', 'texto', []],
            ['formacion_experiencia', 'Formación y experiencia académica-investigativa', 'texto', []],
        ]],
        ['descripcion', 'Descripción de la asignatura', [
            ['descripcion', 'Descripción de la asignatura', 'ficha', ['adjustable' => false]],
        ]],
        ['objetivos', 'Objetivo(s) de la asignatura', [
            ['objetivo_general', 'Objetivo general', 'ficha', ['adjustable' => false]],
            ['objetivos_especificos', 'Objetivos específicos', 'ficha', ['adjustable' => false]],
        ]],
        ['resultados', 'Resultados de aprendizaje de la asignatura', [
            ['resultados_aprendizaje', 'Resultados de aprendizaje', 'ficha', ['adjustable' => false]],
        ]],
        ['habilidades', 'Habilidades blandas de la asignatura', [
            ['habilidades_blandas', 'Habilidades blandas', 'ficha', ['adjustable' => false]],
            ['herramientas_tic', 'Herramientas TIC', 'ficha', ['adjustable' => false]],
        ]],
        ['planificacion', 'Distribución y planificación de las unidades curriculares', [
            ['unidades', 'Planificación de unidades', 'tabla', [
                'weeks' => 16,
                'columns' => [
                    'contenidos' => 'Contenidos temáticos',
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
        ['metodologia', 'Metodología y ambientes de aprendizaje', [
            ['metodologia', 'Métodos de enseñanza-aprendizaje', 'texto', []],
        ]],
        ['evaluacion', 'Evaluación de los aprendizajes', [
            ['indicadores_evaluacion', 'Indicadores de evaluación', 'texto', []],
            ['componentes_evaluacion', 'Escala de valoración', 'texto', []],
        ]],
        ['perfil_egreso', 'Relación de la asignatura con los resultados de aprendizaje del perfil de egreso', [
            ['contribucion_perfil', 'Contribución al perfil de egreso', 'ficha', ['adjustable' => false]],
        ]],
        ['etica', 'Conducta y comportamiento ético', [
            ['conducta_etica', 'Conducta y comportamiento ético', 'texto', []],
        ]],
        ['bibliografia', 'Bibliografía', [
            ['bibliografia', 'Bibliografía básica', 'ficha', ['adjustable' => true]],
            ['bibliografia_complementaria', 'Bibliografía complementaria', 'ficha', ['adjustable' => true]],
        ]],
        ['revision', 'Revisión y aprobación', [
            ['firmas', 'Revisión y aprobación', 'tabla', [
                'rows' => ['elabora' => 'Elaborado por', 'revisa' => 'Revisado por', 'aprueba' => 'Aprobado por'],
                'columns' => ['nombre' => 'Nombre', 'cargo' => 'Cargo', 'firma' => 'Firma'],
            ]],
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
