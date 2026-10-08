# I-206 — Accesibilidad en el encabezado

## Pedido del responsable, 2026-10-10

Un botón de accesibilidad junto al del tema, que abre un modal con las opciones.

## Decisiones

- Las preferencias se guardan en el navegador (`localStorage`, clave `silabos:accesibilidad`) y, con sesión, en la
  cuenta: columna `usuarios.preferencias_accesibilidad_usuario` (`jsonb`, nula por defecto) y
  `PUT authentication/accessibility`. La sesión las devuelve en `user.accessibility`; al iniciar sesión la cuenta
  manda sobre el navegador, y si la cuenta no tiene preferencias se suben las del navegador.
- Opciones: tamaño del texto (100, 115, 130 y 150 %), alto contraste, sin animaciones, subrayar enlaces y
  texto más espaciado. «Sin animaciones» parte activo si el sistema operativo pide reducir el movimiento.
- Se aplican como atributos `data-*` en `<html>` (`src/accessibility.css`) y se inicializan antes de montar la SPA.
- Alto contraste redefine los tokens de color en claro y en oscuro y engrosa el foco.

## Verificación

`useAccessibility.test.ts` y `AccessibilityDialog.test.ts`. Revisión manual de contraste en ambos temas.
