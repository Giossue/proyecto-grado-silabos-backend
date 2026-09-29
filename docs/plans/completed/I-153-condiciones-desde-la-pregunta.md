# I-153 — Condiciones desde la pregunta y nombres de preguntas internas

## Decisión del responsable, 2026-09-28

«Mostrar solo si … es igual a …» por cada parte resultaba difícil de entender. Además, las
preguntas dentro de una parte con diseño propio (como «Formación y experiencia» en la
ficha de identificación) no tenían dónde cambiar su nombre.

## Alcance y trazabilidad

- I-142, I-150, I-151; ADM-06; RF-017..026; RNF de usabilidad.
- Solo interfaz: se guarda igual (`definiciones_campo.mostrar_si` en cada dependiente y
  `properties.*.label` en `SaveTemplateDocument`). Sin cambios de API ni de esquema.
- Cada pregunta Sí/No o de selección de la sección muestra «Si en «pregunta» responden
  [valor], pedir también:» con casillas. Una parte depende de una sola pregunta; marcarla
  en otra la mueve. Cambiar el valor actualiza a todos sus dependientes.
- Se corrige que, en una parte con varias preguntas, guardar asignaba el nombre de la
  parte a la primera pregunta.

## Trabajo

- [x] Nuevo bloque de condiciones y nombres editables en `TemplateFieldActions`.
- [x] Pruebas: sin condición, condición guardada, otra sección e identificación con
      renombre y dependencia.
- [x] Lint, typecheck, 224 pruebas de Vitest y build.
