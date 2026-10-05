# I-180 — Archivos sin carrera

## Pedido del responsable, 2026-10-04

`archivos.carrera_id` sobra: con saber quién subió el archivo (`subido_por`) basta.

## Cambios

- Migración `000188` (no se revierte): elimina la columna `carrera_id`, su llave foránea y su índice.
- Se retira de `StoredObject` y de los tres sitios que la llenaban: exportación del sílabo,
  firma electrónica y logo de facultad.
- Ninguna consulta ni permiso leía esa columna.

## Verificación

Suite completa (329), Pint y PHPStan.
