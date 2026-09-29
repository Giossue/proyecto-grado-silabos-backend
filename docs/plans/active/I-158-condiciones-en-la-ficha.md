# I-158 — Condiciones en la ficha y Propiedades mínimas

## Decisión del responsable, 2026-09-29

«Propiedades de la parte» solo debe tener nombre y orientación, en todas las partes. La
lógica del Sí/No se define en la construcción del documento: la condición vive en la
hoja, no en un formulario aparte.

## Alcance y trazabilidad

- ADM-06, I-142, I-153, I-155, I-156; RF-017..026.
- Sin cambios de esquema: la condición viaja como atributo `showWhen` {key, value} del
  nodo `field`; `definiciones_campo.mostrar_si` sigue siendo la fuente para el formulario
  del docente, la validación y el Word.

## Trabajo

- [x] `TemplateDocument` acepta `showWhen` en fichas `field`.
- [x] `SaveTemplateDocument::saveConditions`: tras crear las preguntas nuevas, valida con
      `FieldConditions::error` y guarda `mostrar_si`; sin `showWhen` se pide siempre.
- [x] `TemplateController`: la hoja servida trae la condición vigente en cada ficha, también
      para diseños anteriores.
- [x] Ficha «Docente»: clic → «¿Cuándo se pide?» (Siempre / Solo si «pregunta» es …) con las
      preguntas de selección de la plantilla y las recién insertadas; marca «· condicional».
- [x] `TemplateFieldActions` reducido a nombre y hoja horizontal.
- [x] `composer verify` (380 pruebas); lint, typecheck, 222 pruebas de Vitest y build.
