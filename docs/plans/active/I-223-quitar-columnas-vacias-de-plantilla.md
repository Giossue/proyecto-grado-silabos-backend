# I-223 — Quitar columnas vacías de la plantilla

## Pedido del responsable, 2026-10-14

Borrar lo que no tiene datos ni función en la plantilla.

## Cambios

- `campos_plantilla.configuracion_campo_plantilla` (siempre `[]`), `secciones_plantilla.descripcion_seccion_plantilla`
  (siempre vacía) y `definiciones_campo.ayuda_definicion_campo` (siempre vacía) se eliminan.
- La API deja de enviar y aceptar `help` en los campos y `description` en las secciones; el
  editor de plantilla ya no ofrece escribir ayuda y las pantallas del docente y de la ficha ya no
  la muestran. La extracción de fichas con IA ya no añade la ayuda del campo al texto que envía.
- Migración `000222` (no se revierte). Aborta si alguna de las tres columnas tiene contenido.
- Se conserva la ayuda de pantallas (`ayudas_pantalla`), que es otra función.

## Verificación

Suite completa, Pint, PHPStan sin errores nuevos, OpenAPI regenerado; frontend typecheck, ESLint y
Vitest.
