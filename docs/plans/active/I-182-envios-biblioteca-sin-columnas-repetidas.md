# I-182 — `envios_biblioteca` sin columnas repetidas

## Pedido del responsable, 2026-10-04

`envios_biblioteca.enviado_por` y `enviado_en` repiten lo que ya guarda `revisiones_silabo`.

## Cambios

- Migración `000190` (no se revierte): elimina las dos columnas y actualiza el trigger
  `proteger_envio_biblioteca`, que ya solo protege la revisión y la verificación única.
- El remitente y la fecha de envío se leen de la revisión (`revision.submitter`,
  `revision.enviado_en`) en la bandeja y el detalle de biblioteca y en la tarjeta de firma.
  La API conserva `sent_at` y `sent_by`.

## Verificación

Suite completa (328), Pint y PHPStan.
