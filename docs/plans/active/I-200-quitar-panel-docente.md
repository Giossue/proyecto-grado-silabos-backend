# I-200 — Quitar el Panel docente (DOC-01)

## Pedido del responsable, 2026-10-07

Docencia no necesita un panel: su pantalla de inicio es **Mis sílabos**.

## Cambios

- Frontend: se elimina la ruta `teacher-panel`; `/docente/panel` redirige a Mis sílabos y
  el inicio del rol docente es `/docente/mis-silabos`. La tarjeta de plazo del panel deja de
  mostrarse para Docencia.
- Backend: `DashboardController` y `SetupChecklist` dejan de calcular métricas, lista de
  puesta en marcha y plazo para Docencia.

## Verificación

`ApiDashboardTest`; typecheck, lint y pruebas del frontend.
