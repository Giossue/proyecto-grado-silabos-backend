# I-172 — Convocatorias y periodos en una sola pantalla

## Pedido del responsable, 2026-10-04

Simplificar Administración: quitar la pantalla **Periodos académicos** y dejar solo
**Convocatorias**, con un formulario único que reciba las fechas del periodo y las de la
convocatoria. Antes, quitar la columna de semanas lectivas de la tabla de periodos.

## Trazabilidad

RF-008..016, RF-034..036 · RN-005..019 · CU-03..06 · ADM-04, ADM-12 · I-110, I-113, I-121.

## Análisis

- Periodo y convocatoria institucional ya son 1:1 (índice único, I-110). No cambia el
  esquema ni las reglas de unicidad.
- Un periodo puede existir sin convocatoria: Coordinación programa materias y paralelos
  sobre él antes de que se abra (COR-14). La fusión debe seguir permitiéndolo.
- Abrir la convocatoria exige que no haya otra en curso y que las carreras tengan
  coordinación; crear el periodo no. Por eso el alta sigue siendo dos llamadas y, si la
  segunda falla, el periodo queda creado y el reintento solo abre la convocatoria.
- `semanas_lectivas` alimenta el sílabo (`AcademicContextSnapshot`); se sigue calculando
  aunque no se muestre.

## Cambios

- Backend: `AcademicGovernanceController::store` devuelve `id` junto con `message`.
- Frontend: `AdminProcessesPage` (una fila por periodo: Período · Clases · Elaboración de
  sílabos · Estado), `ConvocationPeriodSheet` (cuatro fechas obligatorias: periodo y convocatoria),
  `ConvocationRowActions` (abrir convocatoria, editar fechas, eliminar periodo o acciones
  del proceso). `CatalogSection` y `AcademicGovernancePage` solo manejan facultades y
  carreras; la ruta `periodos-academicos` y `?seccion=periodo` redirigen a Convocatorias.
  El Panel apunta sus pasos de periodo y proceso a Convocatorias. Sidebar sin Periodos.
- Docs: `screens.md` (ADM-04, ADM-12), `DECISIONS_STATUS.md` (I-172), trazabilidad.

## Verificación

- Backend: `AcademicStructureTest` (25 pruebas) en verde; Pint.
- Frontend: `AdminProcessesPage.test.ts`, `ConvocationPeriodSheet.test.ts`,
  `AcademicGovernancePage.test.ts`, typecheck y lint.

## Pendiente

- Revisión visual de ADM-12 en la instancia de pruebas.
