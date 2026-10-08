# I-200 — Quitar el Panel docente (DOC-01)

## Estado

Superada por I-205 el 7 de octubre de 2026, por instrucción explícita del responsable
del producto.

## Pedido original del responsable, 2026-10-07

Docencia no necesitaba un panel: su pantalla de inicio era **Mis sílabos**.

## Cambios históricos

- Frontend: se eliminó la ruta `teacher-panel`; `/docente/panel` redirigía a Mis sílabos
  y el inicio del rol docente era `/docente/mis-silabos`.
- Backend: `DashboardController` y `SetupChecklist` dejaron de calcular métricas, lista
  de puesta en marcha y plazo para Docencia.

## Verificación histórica

`ApiDashboardTest`; typecheck, lint y pruebas del frontend.
