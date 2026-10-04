# I-130 — El sílabo sigue al paralelo

## Estado

Implementado el 24 de septiembre de 2026 (decisión del responsable del producto).
Ajustado por I-173 (4 de octubre de 2026): asignar un docente reinicia el sílabo del
paralelo; el docente nuevo no hereda nada del anterior.

## Objetivo

Un sílabo pertenece al paralelo, no a una persona. Su docente es quien tiene **hoy** la
asignación vigente del paralelo (`asignaciones_paralelo` activa → `asignaciones_rol`
activa con rol `docente`). Se elimina la copia por sílabo del docente y el relevo docente.

## Alcance y trazabilidad

- RF-003..006, RF-007..016; RN-001..008; CU-02, CU-03; COR-14, COR-15, DOC-01; CP-F.
- Reemplaza la parte de I-39/I-76 referida al relevo docente. El reemplazo de
  coordinación (I-39) no cambia.

## Decisiones

- Migración `000098`: retira `colaboradores_silabo` y crea la vista de solo lectura
  `docentes_vigentes_silabo` (alcances → asignación de paralelo activa → rol docente
  activo). El historial de quién trabajó queda en revisiones y auditoría.
- `Syllabus::currentTeachers()`, `Syllabus::teachers()` (sobre la vista) y el scope
  `whereCurrentTeacher($user)` (exige cuenta activa) centralizan la regla; los usan
  política, listado docente, Panel, checklist, destinatarios y destino de notificaciones.
- Quitar (`DELETE coordination/academic/asignacion_docente/{id}`) y asignar docente se
  admiten con la convocatoria en curso y en cualquier estado del sílabo; solo un período
  finalizado lo impide. El sílabo no cambia; mientras el paralelo no tenga docente nadie
  lo edita.
- La generación de sílabos sigue exigiendo exactamente un docente vigente por paralelo.
- Desactivar una cuenta sigue bloqueado mientras sea docente vigente de sílabos en curso;
  Coordinación debe asignar otro docente a esos paralelos.
- Se retiran `RelieveTeacher`, su request, la ruta `relieve-teacher`, `TeacherReliefSheet`
  y `relieveCareerTeacher`.

## Pendiente

- "Quitar de la carrera" (cerrar todas las asignaciones de un docente y su rol de
  carrera) no se implementó; hoy se quita asignación por asignación.

## Entregables

- [x] Vista, modelo `CurrentSyllabusTeacher` y scopes.
- [x] Reemplazo de todos los usos de `colaboradores_silabo`.
- [x] Retiro del relevo en backend y frontend.
- [x] `ParallelTeacherChangeTest` reemplaza `TeacherReliefTest`.
- [x] OpenAPI, trazabilidad y documentación de esquema.
