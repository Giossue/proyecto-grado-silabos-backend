# I-129 — Malla: purga solo estructural, aprobados intactos y bloqueo por carrera

## Alcance

Continúa I-127 (cambios cosméticos sin purga) en la malla de Coordinación.

## Entrega

- Frontend: las mutaciones de malla y el alta/edición genérica de asignatura manejan
  `purge_required` con el diálogo común y reenvían con `confirm_purge`.
- Mover una tarjeta, renombrar o cambiar código/ciclo/orden/unidad de una materia no
  purga: `InProgressWork::refreshSubjects` actualiza la ficha de los sílabos en curso.
  Créditos, horas y modalidad siguen purgando. Eliminar una materia no pide purga.
- `InProgressWork::inProgress` excluye sílabos aprobados (como `ResetSyllabus`).
  La aclaración I-144 del 2026-09-27 conserva también expedientes con aprobación
  histórica tras reapertura; consultar su plan para la implementación y verificación
  de esa protección adicional.
- `Convocation::scopeLockingCareerData`: la malla/fuentes de una carrera siguen
  bloqueadas con su convocatoria abierta aunque Administración pause el proceso.
  `running` (trabajo docente) y el bloqueo de registros institucionales no cambian.

## Criterios

Pruebas en `SyllabusProcessTest` y `ReviewWorkflowTest`; `career-academics.test.ts`.
