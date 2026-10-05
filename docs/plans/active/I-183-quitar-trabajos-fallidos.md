# I-183 — Quitar `trabajos_fallidos`

## Pedido del responsable, 2026-10-04

La tabla de trabajos fallidos de Laravel es redundante con `trabajos_en_cola`, que ya guarda el
estado y el error de cada trabajo.

## Cambios

- `config/queue.php`: el driver de fallos pasa a `null` (ya no se lee `QUEUE_FAILED_DRIVER`).
- Migración `000191` (no se revierte): elimina la tabla. Tenía 4 filas de pruebas antiguas.
- La prueba de esquema y los docs dejan de mencionarla.

## Verificación

Suite completa, Pint y PHPStan.
