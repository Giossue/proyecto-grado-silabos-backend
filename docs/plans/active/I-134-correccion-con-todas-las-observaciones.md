# I-134 — Corrección con todas las observaciones pendientes

## Estado

Implementado el 24 de septiembre de 2026.

## Objetivo

Decisión del usuario: cuando Coordinación solicita corrección se envían **todas** las
observaciones abiertas de la revisión vigente; no se elige un subconjunto. Con ello la
tabla puente `solicitud_correccion_observaciones` deja de aportar información.

## Alcance y trazabilidad

- RF de revisión y corrección, CU-03, UI de detalle de revisión de Coordinación.
- Cambia el contrato HTTP de `POST /api/v1/coordination/reviews/{revision}/request-correction`:
  ya no acepta `observation_ids`; solo `justification`.

## Decisiones

- `RequestSyllabusCorrection` bloquea y cuenta las observaciones `abierta` de la
  revisión; si no hay ninguna responde 422 en `revision`: "Registra al menos una
  observación pendiente en esta revisión antes de solicitar corrección."
- La marca `requested` de las observaciones (vista docente "Observaciones de
  coordinación" e historial de revisión) es verdadera para toda observación de una
  revisión con solicitud de corrección.
- Migración `2026_09_24_000102`: elimina `solicitud_correccion_observaciones` (su
  trigger cae con la tabla) y redefine `validar_relaciones_flujo` sin esa rama. El
  `down` recrea tabla, índice, trigger y reconstruye el vínculo derivado.
- `InProgressWork` ya no borra filas de la tabla puente.
- Notificaciones, `NotificationDestination` y exportaciones no leían la tabla; sin
  cambios.
- Frontend: `CoordinationReviewDetailPage` muestra la lista de observaciones
  pendientes que se enviarán y su número, sin casillas; `api/reviews.ts` envía solo
  `justification`.

## Verificación

- `pint`, `phpstan`, `php artisan test tests/Feature/Syllabus tests/Feature/Operations`
  (BD `silabos_ueb_test_obs`), rollback/migrate de `000102`.
- Frontend: `npm run typecheck`, `npx vitest run`.
