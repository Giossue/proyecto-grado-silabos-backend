<?php

return [
    'baseline' => [
        ['identificacion', 'Identificación institucional', [
            ['asignatura', '', 'institutional', null],
        ]],
        ['descripcion', 'Descripción de la asignatura', [
            ['descripcion', 'Descripción de la asignatura', 'free_text', null],
        ]],
        ['objetivos', 'Objetivo(s) de la asignatura', [
            ['objetivo_general', 'Objetivo general', 'free_text', null],
            ['objetivos_especificos', 'Objetivos específicos', 'free_text', null],
        ]],
        ['resultados', 'Resultados de aprendizaje de la asignatura', [
            ['resultados_aprendizaje', 'Resultados de aprendizaje', 'free_text', null],
        ]],
        ['habilidades', 'Habilidades blandas de la asignatura', [
            ['habilidades_blandas', 'Habilidades blandas', 'free_text', null],
        ]],
        ['planificacion', 'Distribución y planificación de las unidades curriculares', [
            ['unidades', 'Planificación de unidades', 'free_text', null],
        ]],
        ['metodologia', 'Metodología y ambientes de aprendizaje', [
            ['metodologia', 'Métodos de enseñanza-aprendizaje', 'free_text', null],
        ]],
        ['evaluacion', 'Evaluación de los aprendizajes', [
            ['indicadores_evaluacion', 'Indicadores de evaluación', 'fixed', 'indicadores'],
            ['componentes_evaluacion', 'Escala de valoración', 'fixed', 'escala'],
        ]],
        ['perfil_egreso', 'Relación de la asignatura con los resultados de aprendizaje del perfil de egreso', [
            ['contribucion_perfil', 'Contribución al perfil de egreso', 'free_text', null],
        ]],
        ['etica', 'Conducta y comportamiento ético', [
            ['conducta_etica', 'Conducta y comportamiento ético', 'fixed', null],
        ]],
        ['bibliografia', 'Bibliografía', [
            ['bibliografia', 'Bibliografía básica', 'free_text', null],
            ['bibliografia_complementaria', 'Bibliografía complementaria', 'free_text', null],
        ]],
        ['revision', 'Revisión y aprobación', [
            ['firmas', 'Revisión y aprobación', 'fixed', 'firmas'],
        ]],
    ],

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
