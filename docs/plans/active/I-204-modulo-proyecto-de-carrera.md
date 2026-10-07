# I-204 — Módulo Proyecto de carrera (ficha de asignatura) con asistente de IA

## Pedido del responsable, 2026-10-07

La ficha no debe estar en «Materias y asignaciones»: va en un módulo aparte llamado **Proyecto de
carrera**, con un asistente (agente) como el que existió antes de I-169.

## Hecho

- Frontend: página `CoordinationCareerProjectPage` (`/coordinacion/proyecto-carrera`, entrada
  «Proyecto de carrera» en el menú de Coordinación). Se quita el botón y el estado de ficha de
  `ScheduledSubjectsTab`.
- Backend: tabla `importaciones_ficha`, `FichaImport`, `RequestFichaImport`, `ApplyFichaImport`,
  `DiscardFichaImport`, trabajos `PrepareFichaImportJob` y `ExtractSubjectFichaJob`, puerta
  `FichaExtractionGateway` (hospedada con `ai.*`; desactivada sin proveedor) y rutas
  `GET|POST coordination/ficha-imports`, `POST …/{import}/apply`, `DELETE …/{import}`.
- Lectura de documentos: solo Excel (PhpSpreadsheet); el PDF queda fuera por ahora (pedido del
  responsable). Búsqueda de fragmentos por nombre o código de la materia.

## Decisiones

- No se restaura el pipeline anterior (OpenParser, conceptos curriculares): la IA propone solo los
  campos de ficha que ya define la plantilla y la confirmación escribe en `valores_campo`.
- Una importación por carrera a la vez; una propuesta sin aplicar se reemplaza al subir nuevos documentos.

## Pendiente de entorno

`AI_DRIVER`, `AI_BASE_URL`, `AI_MODEL` y `AI_API_KEY` en el servidor; cola `ia` con un worker.

## Verificación

`FichaImportTest` (propuesta, aplicación parcial, permisos y sin IA), `FichaImportDialog.test.ts`.
