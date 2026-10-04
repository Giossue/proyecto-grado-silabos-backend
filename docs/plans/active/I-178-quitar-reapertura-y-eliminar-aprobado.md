# I-178 — Quitar la reapertura y permitir eliminar un sílabo aprobado

## Pedido del responsable, 2026-10-04

Un sílabo aprobado no se cambia, y que haga falta es casi imposible. En lugar de reabrirlo,
Coordinación puede eliminarlo y ya.

## Decisión

- Se retira la **reapertura**: acción, ruta, pantalla, aviso `silabo.reabierto` y las cinco
  columnas de reapertura que I-176 había puesto en `aprobaciones`.
- Nueva acción **Eliminar sílabo** (`DELETE /api/v1/coordination/syllabi/{syllabus}`):
  - solo el coordinador de la carrera, solo sobre un sílabo `aprobado` y con la convocatoria
    abierta o en pausa (los demás estados siguen usando **Reiniciar**);
  - borra el sílabo con todo su historial (revisiones, aprobación, PDF, firmas y envío a
    biblioteca) y crea otro `sin_iniciar` para el mismo paralelo, igual que el cambio de
    docente de I-174; el docente empieza de cero;
  - audita `silabo.eliminado` y avisa al docente.
- `aprobaciones` vuelve a ser totalmente inmutable (trigger `proteger_aprobacion` original);
  las columnas `correccion_*` de `revisiones_silabo` siguen siendo de escritura única.
- El decano ya no reabre; solo aprueba, devuelve o firma.

## Cambios

- Migración `000186` (no se revierte): elimina las columnas de reapertura y restaura el
  trigger y la función de validación.
- Backend: `DeleteSyllabus`, `SyllabusPolicy::delete`, `InProgressWork::restartSyllabus`;
  se retiran `ReopenSyllabus` y sus consumos en `LibraryReviews`, `SignatureWorkflow`,
  `ReviewController` y `SyllabusController`. El detalle de la revisión trae `can_delete` en
  lugar de `can_reopen` y ya no trae `reopening`.
- Frontend: tarjeta **Eliminar sílabo aprobado** con confirmación; sin alerta ni tarjeta de
  reapertura.
- ADR-0005 queda reemplazado; ADR 0002 enmendado.

## Verificación

- `ReviewWorkflowTest`: el coordinador elimina un aprobado, el paralelo queda con un sílabo
  `sin_iniciar`, decano y docente no pueden, y la aprobación es inmutable.
- Frontend `CoordinationReviewDetailPage.test.ts`: confirmación y regreso a la cola.
- Suite completa, Pint, PHPStan, contrato OpenAPI, typecheck, ESLint y Vitest.
