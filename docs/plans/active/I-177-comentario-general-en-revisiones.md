# I-177 — Comentario general en las revisiones

## Pedido del responsable, 2026-10-04

Biblioteca, coordinación y decanato devuelven el sílabo sin observaciones por campo:

- **Biblioteca** solo ve la bibliografía y devuelve eligiendo motivos predefinidos, sin
  espacio para texto personalizado.
- **Coordinación y decanato** escriben un comentario general; coordinación deja de pedir
  observaciones previas.

## Decisión

| Antes | Ahora |
|---|---|
| Tabla `observaciones_revision` (campo, sección, estado abierta/verificada) | Se elimina (migración `000185`, vacía en la remota). |
| Coordinación registraba observaciones y luego pedía corrección con una justificación | Pide corrección con un comentario general (`justification`, mínimo 10 caracteres). |
| Biblioteca escribía un comentario libre | Marca uno o más motivos (`reasons[]`); el sistema guarda su texto en `correccion_justificacion`. |
| El docente veía observaciones por campo y `library_comments` | Ve `review_comments` (todas las etapas) con la etapa de cada comentario. |

Motivos iniciales de biblioteca, **POR VALIDAR** con Biblioteca (`LibraryCommentReasons`):
libro no disponible, código incorrecto, ISBN incorrecto, edición desactualizada y datos
incompletos de la obra.

## Cambios

- Backend: se retiran `ReviewObservation`, `AddReviewObservation`, `StoreObservationRequest`,
  la ruta `coordination/reviews/{revision}/observations`, los conteos de pendientes y el
  paso que daba por resueltas las observaciones al aprobar. `RequestSyllabusCorrection` ya
  no exige observaciones. Nuevos `ReviewComments` y `LibraryCommentReasons`; el detalle de
  biblioteca trae `comment_reasons` y su comentario recibe `reasons`.
- API: `observations` y `library_comments` salen del detalle del docente y de la revisión;
  entra `review_comments`. `unresolved_observations` sale de la cola de coordinación.
- Frontend: coordinación y decanato sin panel de observaciones ni columna «Pendientes»;
  biblioteca con casillas de motivos; el docente ve un solo bloque de comentarios.

## Verificación

- `LibraryReviewTest`: motivos válidos, rechazo de texto libre, vacío y motivos inventados.
- `ReviewWorkflowTest`: corrección de coordinación con comentario general, rutas retiradas.
- Frontend: páginas de biblioteca, coordinación y docente; esqueleto de carga.
- Suite completa, Pint, PHPStan, contrato OpenAPI, typecheck, ESLint y Vitest.
