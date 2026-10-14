# I-224 — Cambiar de docente sin pausar la convocatoria

## Pedido del responsable, 2026-10-14

Tras el sorteo de materias un docente puede retractarse. Cambiar o quitar al docente de un paralelo
debe poder hacerse con la convocatoria abierta; el nuevo docente empieza desde cero.

## Hallazgo

El backend ya lo permitía (`asignacion_docente` no pasa por el bloqueo de carrera y reemplazar al
docente rehace el sílabo desde cero; lo prueban `ParallelTeacherChangeTest`). Lo que lo impedía era
la pantalla Materias y asignaciones, que se bloqueaba entera.

## Cambios

- Backend: con la convocatoria abierta `scheduled-subjects` marca los paralelos `editable: false` y
  `deletable: false` (antes `editable: true` aunque editarlos fallara).
- Frontend: con el bloqueo, la fila se abre para cambiar el docente; se ocultan crear paralelo, jornada
  y las acciones masivas de agregar paralelo y asignar jornada. El aviso lo explica.
- Documentación: se corrige «Relevo del docente responsable», que describía reglas anteriores.

## Verificación

Prueba de backend nueva, prueba de la pestaña actualizada; suite completa, Pint, tipos, ESLint y Vitest.
