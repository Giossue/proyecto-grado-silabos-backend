# I-93 — Consolidación de triggers de invariantes

## Objetivo

Reducir hooks duplicados sin retirar invariantes de base de datos.

## Cambio

- `revisiones_ia_silabo` conserva una sola función y un solo trigger para `UPDATE OR
  DELETE`.
- `ejecuciones_ia` conserva una sola función y un solo trigger para las validaciones de
  plantilla, revisión integral e inmutabilidad.
- La migración `000088` es reversible y restaura la distribución anterior en `down`.

## Verificación

- La migración local deja 35 triggers no internos.
- Los nombres y funciones consolidados siguen cubriendo las mismas operaciones.
- No se modifica la semántica de los mensajes ni de las restricciones.
