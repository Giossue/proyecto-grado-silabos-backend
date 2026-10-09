# I-210 — Plantilla simple: un documento, secciones y campos sin tipo

## Pedido del responsable, 2026-10-09

La plantilla actual resulta complicada. La plantilla es **todo el documento** del sílabo de
ejemplo (`temp/silabo-ejemplo/SILABOCALIDAD_SWPAO_II_2626_signed-signed (2).pdf`). Desde ahí,
Administración solo agrega, quita, ordena y edita secciones y campos. Un campo no tiene «tipo»
(texto, tabla, lista...): es un espacio con editor libre donde se puede escribir texto, listas y
tablas. No hay tablas especiales por sección: no escala.

## Modelo

- **Plantilla**: una sola, sin versiones (I-32). Título y logos fijos (no son campos).
- **Sección**: título y orden; contiene campos. Puede ser horizontal (hoja apaisada).
- **Campo**: título, ayuda opcional, **quién lo llena** y contenido (documento libre).
  - **Docente**: el docente escribe sobre el contenido inicial que puso Administración.
  - **Coordinación**: se llena una vez por materia en la ficha y se hereda; casilla «el docente
    puede ajustarlo».
  - **Institucional**: contenido definido en la plantilla; solo lectura para el docente; puede
    llevar variables.
- **Variables**: datos del sistema que Administración inserta dentro del contenido (facultad,
  carrera, modalidad, campus, asignatura, período, ciclo, paralelo, jornada, código, prerrequisitos,
  correquisitos, unidad de organización curricular, horas y créditos, docente, correo, firmas y
  fechas). Ya existen como `template_variables`; solo deja de haber tabla automática especial.

Reemplaza los cinco tipos (`texto`, `ficha`, `tabla`, `automatico`, `fijo`, ADR-0009).

## Plantilla oficial (contenido de partida, tomado del PDF)

| # | Sección | Campos | Quién llena |
|---|---|---|---|
| 1 | Identificación institucional | Datos de identificación (tabla con variables) | Institucional |
|   |  | Estudiantes con discapacidad | Docente |
|   |  | Formación y experiencia académica – investigativa | Docente |
| 2 | Descripción de la asignatura | Descripción | Coordinación (docente ajusta) |
| 3 | Objetivo(s) de la asignatura | Objetivo general y objetivos específicos | Coordinación (docente ajusta) |
| 4 | Resultados de aprendizaje | Resultados de aprendizaje | Coordinación |
| 5 | Habilidades blandas | Habilidades blandas | Coordinación (docente ajusta) |
| 6 | Distribución y planificación de las unidades curriculares (horizontal) | Unidades (una tabla por unidad: nombre, resultados, 16 semanas con horas ACD/APE/AA, actividades y evaluación) | Docente |
| 7 | Metodología y ambientes de aprendizaje | 7.1 Métodos, 7.2 Técnicas, 7.3 Recursos, 7.4 Herramientas, 7.5 Ambientes | Docente |
| 8 | Evaluación de los aprendizajes | Indicadores por parcial y escala de valoración | Institucional |
| 9 | Relación con el perfil de egreso | Resultados de la asignatura frente a los de la carrera | Coordinación |
| 10 | Conducta y comportamiento ético | Texto institucional | Institucional |
| 11 | Bibliografía | Básica y complementaria | Coordinación |
| — | Revisión y aprobación | Firmas, nombres y fechas de docente y coordinación | Institucional |

## Decisiones tomadas

- Una sola opción «quién lo llena» por campo (confirmado).
- Sin tablas especiales por sección: tablas, listas y texto salen del mismo editor libre
  (confirmado).
- Se reemplaza la plantilla y los sílabos en curso por la del PDF; los aprobados se conservan
  (confirmado, mismo criterio que I-118).

## Por validar

- Los totales de horas de la sección 6 se pierden como cálculo automático: el docente los escribe.
  Se podría validar luego contra las horas de la materia.
- Cuáles de las secciones 2, 3, 5 y 7 llena Coordinación y cuáles el docente (la tabla es una
  propuesta).
- El docente edita tablas libremente (puede agregar filas); antes eran filas fijas.

## Etapas (sin empezar)

1. Documentación: ADR que reemplaza ADR-0009, `field-types.md`, especificación y trazabilidad.
2. Backend: campo con `quien_llena` y contenido documento; migración y reinicio de sílabos;
   herencia de ficha, snapshot y Word/PDF por documento; retiro de tipos y compatibilidad.
3. Frontend: constructor (agregar sección/campo, elegir quién lo llena, editar contenido) y
   editor del docente.
4. Plantilla oficial cargada desde `config/syllabus_template.php`.
