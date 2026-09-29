# I-154 — Texto fijo dentro de «¿Quién llena esta parte?»

## Decisión del responsable, 2026-09-28

El texto fijo no necesita una acción aparte ni un botón en la cinta: es otra respuesta a
quién llena la parte.

## Alcance y trazabilidad

- I-151; ADM-06; RF-017..026; RNF de usabilidad.
- `TemplatePartKindPicker` agrega «Nadie, es un texto fijo» al crear una parte o sección
  (`content_type: fixed`, ya aceptado por la API). No se ofrece al editar, igual que el
  dato automático: el servidor no convierte una parte del docente en texto fijo.
- Se retiran la entrada «Agregar texto fijo» del menú «+» y el botón «Hacer texto fijo»
  de la cinta, con su acción `convertTableToFixed`. Sin cambios de API ni de esquema.

## Trabajo

- [x] Opción en el selector; creador de partes sin variante propia de texto fijo.
- [x] Retiro de la entrada del menú y del botón de la cinta.
- [x] Pruebas del selector; lint, typecheck, 226 pruebas de Vitest y build.
