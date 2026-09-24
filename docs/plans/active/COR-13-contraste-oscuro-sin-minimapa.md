# COR-13 — Contraste de Malla en oscuro y limpieza del lienzo

## Alcance y trazabilidad

RF-008..016, RN-005..008, CU-03, COR-13 y CP-F estructura académica; RNF-018..023 y
CP-N de accesibilidad/experiencia. La petición visual del responsable del producto del
2026-09-24 pide mayor contraste en el constructor oscuro y retirar el minimapa de la
esquina inferior derecha. La corrección posterior retira la franja que infería una unidad
predominante por ciclo. La revisión visual final replica la jerarquía del tema claro:
ciclos neutros apenas más oscuros que las tarjetas y color de unidad en su badge, leyenda
y encabezados académicos. Los paneles laterales de nivel y totales conservan en oscuro el
tinte primario suave que muestran en claro. No depende de un `PV` abierto. PV-19 sigue
pendiente para la aceptación en dispositivos y navegadores reales.

## Entrega

- Diferenciar lienzo, carriles y tarjetas con la jerarquía semántica del tema claro; en
  oscuro, el carril queda apenas más oscuro que la tarjeta de materia.
- Aplicar el color de unidad al badge de la materia, la leyenda y los encabezados ACD,
  APE, AA, créditos y total; el resto de la tarjeta usa superficies neutras.
- Conservar el tinte primario suave en los paneles laterales de nivel, asignaturas y total
  tanto en claro como en oscuro.
- Mantener legibles el texto principal, los metadatos, las insignias y los totales.
- Quitar el minimapa y su dependencia; conservar zoom y desplazamiento del lienzo.
- Quitar la franja lateral del ciclo y su cálculo de unidad predominante; conservar la
  unidad propia de cada materia y su leyenda.
- Comprobar en Chromium ambos temas y el comportamiento principal del constructor;
  ejecutar lint y tipos de la SPA.

## Criterios

La Malla oscura sigue la composición visual de la clara: fondo neutro, ciclo ligeramente
más oscuro y tarjeta de materia más clara. El badge, la leyenda y los encabezados
académicos identifican con color la unidad curricular; los laterales del ciclo usan el
tinte primario suave. El minimapa y la franja de unidad por ciclo no aparecen. El usuario
aún puede ampliar, reducir y mover el lienzo.

## Verificación

- `npm run lint`, `npm run typecheck` y los dos tests Vitest focalizados: aprobados.
- `tests/e2e/curriculum-drag.spec.ts` en Chromium: dos recorridos aprobados; el arrastre
  conserva el ciclo y guarda o restaura el orden según la respuesta de la API.
- Render sintético en Chromium, claro y oscuro: ningún `.vue-flow__minimap` y dos botones
  de zoom. En claro, el carril queda apenas más oscuro que las tarjetas blancas.
- Render sintético final inspeccionado en Chromium: cuatro ciclos neutros, ninguna
  etiqueta vertical de unidad y una cabecera de nivel por ciclo. El carril es más oscuro
  que la tarjeta; badge y encabezados académicos heredan el color de unidad.
- La aceptación perceptiva en dispositivos reales sigue pendiente de PV-19.
