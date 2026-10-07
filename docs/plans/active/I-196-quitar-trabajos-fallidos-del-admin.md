# I-196 — Quitar la pantalla Trabajos fallidos (ADM-09)

## Pedido del responsable, 2026-10-07

Sin reintento de trabajos y sin pantalla de trabajos fallidos en Administración. El fallo ya
queda en `trabajos_en_cola` y en la auditoría.

## Cambios

- Backend: se eliminan `JobExecutionController`, `RetryJobExecution`, sus dos requests y las
  rutas `admin/job-executions` (listado y reintento). La etiqueta de auditoría
  `trabajo.reintento_solicitado` se conserva para eventos históricos.
- Frontend: se elimina `AdminJobsPage`, su ruta `/admin/procesos`, el alias legado y el
  enlace desde Auditoría.
- Documentos: ADM-09 se marca como retirada en `screens.md`, la especificación de
  documentos y las pruebas.
- Documentos (DOC): la tabla de solicitudes muestra los botones DOCX y PDF en la columna
  Acciones, sin menú.

## Verificación

Suite backend, Pint, PHPStan; typecheck, lint y pruebas del frontend.
