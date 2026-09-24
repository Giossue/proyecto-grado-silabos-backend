# COR-13 — Contraste de Malla en oscuro y retiro del minimapa

## Alcance y trazabilidad

RF-008..016, RN-005..008, CU-03, COR-13 y CP-F estructura académica; RNF-018..023 y
CP-N de accesibilidad/experiencia. La petición visual del responsable del producto del
2026-09-24 pide mayor contraste en el constructor oscuro y retirar el minimapa de la
esquina inferior derecha. No depende de un `PV` abierto. PV-19 sigue pendiente para la
aceptación en dispositivos y navegadores reales.

## Entrega

- Diferenciar lienzo, carriles, tarjetas y sus bordes dentro de COR-13 mediante tokens
  semánticos acotados al constructor, sin alterar las otras pantallas ni la paleta clara.
- Mantener legibles el texto principal, los metadatos, las insignias y los totales.
- Quitar el minimapa y su dependencia; conservar zoom y desplazamiento del lienzo.
- Comprobar en Chromium ambos temas y el comportamiento principal del constructor;
  ejecutar lint y tipos de la SPA.

## Criterios

La Malla oscura separa visualmente cada nivel y materia y sus textos conservan contraste
legible. La Malla clara conserva sus superficies. El minimapa ya no ocupa ni tapa la
esquina inferior derecha. El usuario aún puede ampliar, reducir y mover el lienzo.

## Verificación

- `npm run lint`, `npm run typecheck` y los dos tests Vitest focalizados: aprobados.
- `tests/e2e/curriculum-drag.spec.ts` en Chromium: dos recorridos aprobados; el arrastre
  conserva el ciclo y guarda o restaura el orden según la respuesta de la API.
- Render sintético en Chromium, claro y oscuro: ningún `.vue-flow__minimap` y dos botones
  de zoom. La paleta clara conserva fondos blancos en ciclos y tarjetas.
- En oscuro, el contraste calculado con colores computados fue 7,75:1 para el texto
  principal de tarjeta, 5,74:1 para metadatos, 5,51:1 para la insignia y 4,97:1 para
  el encabezado de horas. La separación tonal entre carril y tarjeta fue 1,69:1.
- La aceptación perceptiva en dispositivos reales sigue pendiente de PV-19.
