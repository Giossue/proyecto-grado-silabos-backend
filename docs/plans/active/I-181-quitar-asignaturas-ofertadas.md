# I-181 — Quitar `asignaturas_ofertadas`

## Pedido del responsable, 2026-10-04

A nivel institucional siempre se ofrecen todas las materias de la malla; no existe ofertar una
malla sin dar ciertas materias. Por eso la tabla que decía qué materias se abren en un período
sobra, y el paralelo apunta directo al período y a la materia.

## Decisión

- Se elimina `asignaturas_ofertadas` (migración `000189`, no se revierte). `paralelos` gana
  `periodo_academico_id` y `asignatura_id` y pierde `asignatura_ofertada_id`. La unicidad pasa
  a (período, materia, código de paralelo).
- Desaparece la acción de quitar una materia de un período (`DELETE scheduled-subjects/{id}`)
  y el tipo de registro `programacion_asignatura`. Preparar período sigue exigiendo incluir
  todas las materias activas que aún no tienen paralelos.
- `Parallel` toma `subject`, `academicPeriod`, `campus_programacion` y `modalidad`; `Subject`
  deriva campus y modalidad. `AcademicContextSnapshot`, `InheritMasterValues` y la generación
  de sílabos trabajan con el paralelo.

## API

- `GET coordination/academic/scheduled-subjects`: una fila por período y materia activa de la
  malla (con o sin paralelos). Ya no trae `id` ni `active`; se identifica con `subject_id` y
  `period_id`. `options.scheduledSubjects` tampoco trae `id`.
- `POST coordination/academic/parallels`: `subject_id` y `period_id` reemplazan a
  `scheduled_subject_id`.
- La preparación del período ya no informa «materias programadas» creadas.

## Verificación

Suite completa (328), Pint, PHPStan y contrato OpenAPI; frontend: typecheck, ESLint y Vitest
(291), con las pruebas de la pestaña de materias, la hoja de paralelos y la preparación.
