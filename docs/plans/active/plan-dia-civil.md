# Plan — Día civil institucional

## Problema

La fecha «de hoy» se resolvía en UTC en el servidor y en la zona del navegador en la SPA.
Entre las 19:00 y las 24:00 (hora de Guayaquil, UTC-5) el día civil local ya es el día
siguiente en UTC, así que:

- abrir una convocatoria con inicio «hoy» se rechaza con «El inicio no puede ser anterior
  a hoy»;
- el plazo de entrega se cerraba 5 horas antes de la medianoche local;
- la fecha de inicio de una convocatoria se mostraba un día antes en la pantalla de
  procesos;
- el selector de fechas del navegador ofrecía un mínimo distinto al del servidor.

## Decisión

El servidor es la autoridad del día civil y ese día es el de la institución
(`DISPLAY_TIMEZONE`, por defecto `America/Guayaquil`). El navegador nunca decide el día de
negocio: recibe la fecha del servidor y la refleja.

Tres capas, con zonas distintas:

| Capa | Zona | Dónde |
|---|---|---|
| Instante (auditoría, firmas, avisos) | UTC | base de datos y `APP_TIMEZONE` |
| Día civil de negocio («hoy», plazos) | `DISPLAY_TIMEZONE` | `App\Support\InstitutionDate` |
| Presentación | `DISPLAY_TIMEZONE` | SPA (`formatDateDisplay`) |

Una fecha sin hora es un día civil, no un instante. Se guarda con los límites reales de
ese día en la zona institucional (medianoche para el inicio, final del día para la
entrega) y la API la publica como `YYYY-MM-DD`. Los datos ya guardados con el marcador
antiguo (medianoche y fin del día en UTC) se reinterpretan con una migración.

## Alcance

Backend:

1. `App\Support\InstitutionDate`: zona, hoy, día civil de un instante y límites del día.
2. `ProcessDates`: las fechas sin hora se convierten en los límites del día institucional.
3. `StoreSyllabusProcessRequest`: inicio y entrega comparados con el día civil.
4. `ExtendProcessDeadline` y su request: la prórroga no puede caer en un día ya pasado.
5. `ConvocationSchedule`: la ventana de envío se decide por día civil.
6. `CoordinatorConvocationReadiness`: el plazo sigue abierto si el día civil no pasó.
7. `Syllabus`: período vigente y pasado con el día civil.
8. `DashboardController`: estado, días restantes y mensajes con el día civil.
9. `AcademicPeriodPlanning`, `StoreAcademicRecordRequest` y `AuditEventController`: un
   solo origen para la zona institucional.
10. API: `inicia_en` y `entrega_en` se publican como fecha (`Y-m-d`), no como instante.
11. `/api/v1/session`: publica `institution.today` e `institution.timezone`.
12. Migración `2026_10_21_000226`: reinterpretación de los plazos ya guardados.

SPA (repositorio `proyecto-grado-silabos-frontend`):

13. `Session` incorpora `institution`.
14. `useInstitutionToday` entrega el día del servidor; los selectores de fecha lo usan
    como mínimo y valor por defecto.

Documentación: `docs/architecture/backend.md`, `docs/architecture/database.md`,
`docs/architecture/frontend.md`, `docs/quality/traceability.md`, `DECISIONS_STATUS.md`.

## Fuera de alcance

- Instantes que sí son instantes (auditoría, firmas, notificaciones): no se tocan.
- Horario de verano u otras zonas: la UEB opera en `America/Guayaquil`, sin cambios.

## Verificación

- `composer verify` (Pint, PHPStan nivel 7, Pest).
- `npm run typecheck`, `npm run lint` y `npm test` en la SPA. `npm run contract:check`
  ya fallaba antes de este cambio (ver `docs/plans/pendientes.md`).
- Pruebas nuevas: día civil en la convocatoria institucional, prórroga, ventana de envío,
  panel y contrato de sesión.
