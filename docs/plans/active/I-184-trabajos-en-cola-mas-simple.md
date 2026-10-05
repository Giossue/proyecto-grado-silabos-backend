# I-184 — `trabajos_en_cola` más simple

## Pedido del responsable, 2026-10-04

Simplificar la tabla de trabajos en segundo plano: de 17 a 14 columnas, sin perder funciones.

## Cambios

- Se eliminan `progreso` (el estado ya dice si va en curso), `intentos_maximos` (el límite vive en
  el código) y `codigo_error` (quedan solo los mensajes seguros en `mensaje_error`).
- Migración `000192` (no se revierte): también borra las filas de las importaciones del
  Proyecto de carrera (`curriculo.extraer`, `fuente_academica.openparser`).
- `PlatformSmokeJob` ya no guarda el texto de la excepción (lo manda al registro); así todo
  `mensaje_error` es seguro y la API lo muestra sin filtrar por código.
- API: `progreso` e `intentos_maximos` salen de la lista de procesos y del detalle de
  documentos; el frontend ya no muestra el porcentaje ni «máximo N por ciclo».

## Verificación

Suite completa (328), Pint, PHPStan, contrato OpenAPI; frontend typecheck, ESLint y Vitest (291).
