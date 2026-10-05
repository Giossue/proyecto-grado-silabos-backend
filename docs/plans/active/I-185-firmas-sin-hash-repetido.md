# I-185 — `firmas_documento` sin hash repetido

## Pedido del responsable, 2026-10-04

`firmas_documento.hash_archivo` repetía la huella del PDF firmado, que ya guarda `archivos`.

## Cambios

- Migración `000193` (no se revierte): elimina la columna. La firma conserva el certificado
  (titular, serie, emisor y vencimiento) como evidencia.
- Se retira del modelo y de `AcceptDocumentSignature`. Nada leía esa columna: la comprobación
  de «el documento cambió» usa la huella del archivo vigente.

## Verificación

Suite completa (328), Pint y PHPStan.
