# I-222 — Quitar columnas repetidas

## Pedido del responsable, 2026-10-14

Borrar lo que está duplicado en la base de datos. Las columnas vacías o sin uso de funciones que
aún no se estrenaron se revisan aparte.

## Cambios

- `definiciones_campo`: se quitan `clave_definicion_campo`, `etiqueta_definicion_campo` y
  `posicion_definicion_campo`. La clave y el título de cada campo viven en `campos_plantilla`
  (iguales en todos los registros) y la posición era siempre 1. La restricción única
  `(campo_plantilla_id, posicion)` pasa a ser única por `campo_plantilla_id`: un campo, una definición.
  La clave sigue siendo única por plantilla en `campos_plantilla`. `FieldDefinition` sigue exponiendo
  `clave` y `etiqueta` leyéndolas del bloque, y `where('clave', ...)` consulta el bloque.
- `envios_biblioteca`: se quitan `verificado_por` y `verificado_en`. Biblioteca aprueba y crea la
  aprobación en el mismo paso, con el mismo usuario y hora, así que esos datos ya están en
  `aprobaciones`. El envío queda como registro inmutable de que la revisión llegó a biblioteca.
- Migración `000221` (no se revierte). Aborta si los títulos o claves no coinciden, si hay más de una
  definición por campo o si hay verificaciones de biblioteca sin aprobación.
- La API conserva `library_verified_at/by` y `verified_at/by`, ahora desde la aprobación.

## Verificación

Suite completa, Pint, PHPStan sin errores nuevos y frontend sin cambios de contrato.
