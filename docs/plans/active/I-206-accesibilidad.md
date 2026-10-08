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

## Teclado y lector de pantalla (auditoría 2026-10-10)

- Hecho: título de pestaña por ruta y foco al contenido tras navegar, enlace «Saltar al contenido»,
  `<main>` en el acceso, Espacio en las filas clicables, tablas con nombre, anuncio del estado de guardado,
  avisos de error de 10 s y paginación sin anuncios duplicados.
- Malla curricular sin arrastrar: el menú de cada materia trae «Mover a la izquierda/derecha», «Requiere otra
  materia…» y «Es requisito de otra materia…», y el botón «Relaciones» abre la lista de relaciones con su eliminación.
- Pendiente: lista de errores enlazada al campo (`aria-describedby`), foco al cerrar diálogos abiertos desde menús y
  jerarquía de encabezados en hojas y nodos.
