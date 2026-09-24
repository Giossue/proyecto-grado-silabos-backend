# COR-13 — Contraste de Malla en oscuro y limpieza del lienzo

## Alcance y trazabilidad

RF-008..016, RN-005..008, CU-03, COR-13 y CP-F estructura académica; RNF-018..023 y
CP-N de accesibilidad/experiencia. La petición visual del responsable del producto del
2026-09-24 pide mayor contraste en el constructor oscuro y retirar el minimapa de la
esquina inferior derecha. La corrección posterior retira la franja que infería una unidad
predominante por ciclo. La revisión visual final sustituye los grises neutros por tonos
azul grisáceo oscuros para reducir la fatiga expresada por el usuario. No depende de un
`PV` abierto. PV-19 sigue pendiente para la aceptación en dispositivos y navegadores reales.

## Entrega

- Diferenciar lienzo, carriles, tarjetas y sus bordes dentro de COR-13 mediante tonos azul
  grisáceo semánticos acotados al constructor, sin alterar las otras pantallas ni la
  paleta clara.
- Mantener legibles el texto principal, los metadatos, las insignias y los totales.
- Quitar el minimapa y su dependencia; conservar zoom y desplazamiento del lienzo.
- Quitar la franja lateral del ciclo y su cálculo de unidad predominante; conservar la
  unidad propia de cada materia y su leyenda.
- Comprobar en Chromium ambos temas y el comportamiento principal del constructor;
  ejecutar lint y tipos de la SPA.

## Criterios

La Malla oscura usa superficies azul grisáceo oscuras, separa visualmente cada nivel y
materia y sus textos conservan contraste legible. La Malla clara conserva sus superficies.
El minimapa y la franja de unidad por ciclo no aparecen. El usuario aún puede ampliar,
reducir y mover el lienzo.

## Verificación

- `npm run lint`, `npm run typecheck` y los dos tests Vitest focalizados: aprobados.
- `tests/e2e/curriculum-drag.spec.ts` en Chromium: dos recorridos aprobados; el arrastre
  conserva el ciclo y guarda o restaura el orden según la respuesta de la API.
- Render sintético en Chromium, claro y oscuro: ningún `.vue-flow__minimap` y dos botones
  de zoom. La paleta clara conserva fondos blancos en ciclos y tarjetas.
- Render sintético final inspeccionado en Chromium: cuatro ciclos en tonos azul grisáceo,
  ninguna etiqueta vertical de unidad y una cabecera de nivel por ciclo. Los colores
  computados coinciden con la guía recibida para lienzo, carril, tarjeta, tarjeta
  secundaria, bordes, textos y relaciones.
- En oscuro, el contraste calculado es 12,00:1 para el texto principal de tarjeta y
  6,12:1 para sus metadatos. Prerrequisito y correquisito alcanzan 4,81:1 y 5,37:1
  respectivamente sobre el lienzo.
- La aceptación perceptiva en dispositivos reales sigue pendiente de PV-19.
