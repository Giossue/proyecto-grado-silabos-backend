# I-224 — Paralelos y docentes sin pausar la convocatoria

## Pedido del responsable, 2026-10-14

Tras el sorteo de materias un docente puede retractarse. Cambiar o quitar al docente, y también crear,
editar o eliminar paralelos, debe poder hacerse con la convocatoria abierta: pausarla interrumpe a todos
los docentes de la carrera por el cambio de unos pocos. El nuevo docente empieza desde cero.

## Hallazgo

El backend ya permitía reemplazar al docente con la convocatoria abierta (`asignacion_docente` no pasa
por el bloqueo de carrera; lo prueban `ParallelTeacherChangeTest`). Lo que impedía el resto era
`assertCareerEditable` en crear, editar y eliminar paralelos, y la pantalla que se bloqueaba entera.

## Cambios

- Backend: `CreateParallels`, `UpdateParallel` y `DeleteParallel` ya no exigen la convocatoria pausada.
  `UpdateParallel` actualiza la identificación del sílabo del paralelo (`InProgressWork::refreshParallel`)
  y, si el sílabo está en revisión o aprobado, pide confirmación (`purge_required`) y lo rehace desde cero
  (`InProgressWork::restartSentForParallel`); el frontend usa el diálogo de confirmación existente.
- Frontend: Materias y asignaciones ya no se bloquea ni muestra el aviso de edición bloqueada.
  La malla y las fichas (Proyecto de carrera) siguen exigiendo pausa.
- Documentación: se corrige «Relevo del docente responsable».

## Verificación

Pruebas de backend nuevas y actualizadas; suite completa, Pint, tipos, ESLint y Vitest.
