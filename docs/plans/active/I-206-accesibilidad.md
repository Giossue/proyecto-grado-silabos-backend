# I-206 — Accesibilidad en el encabezado

## Pedido del responsable, 2026-10-10

Un botón de accesibilidad junto al del tema, que abre un modal con las opciones.

## Decisiones

- Las preferencias son por navegador (`localStorage`, clave `silabos:accesibilidad`); no hay tabla ni API.
- Opciones: tamaño del texto (100, 115, 130 y 150 %), alto contraste, sin animaciones, subrayar enlaces y
  texto más espaciado. «Sin animaciones» parte activo si el sistema operativo pide reducir el movimiento.
- Se aplican como atributos `data-*` en `<html>` (`src/accessibility.css`) y se inicializan antes de montar la SPA.
- Alto contraste redefine los tokens de color en claro y en oscuro y engrosa el foco.

## Verificación

`useAccessibility.test.ts` y `AccessibilityDialog.test.ts`. Revisión manual de contraste en ambos temas.
