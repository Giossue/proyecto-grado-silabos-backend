# Límite de caracteres por campo

Objetivo: que Administración ponga un límite de caracteres a lo que el docente escribe en un campo. Rama: main (commits locales, sin push).
Entrega: commits en backend y frontend. Pruebas: Pest (`php -d memory_limit=1G vendor/bin/pest`), Vitest.

## Specs

- S1. Pedido: «Algo que me pidieron es que haya un limite de caracteres en ciertos campos, tocaría ver como poner, porque como que existen muchos campos complejos, analiza eso, o sea creo que quiere que se ponga un límite de caracteres que pueda ingresar el docente, pero por campos».
- S2. Quién: «Solo Administración». Se pone en la plantilla, con el campo seleccionado, desde la cinta («Límite de caracteres»), solo en campos editables (Carrera). Vale igual para todas las carreras y materias. Opcional: sin límite, todo sigue como hoy.
- S3. Qué cuenta: el texto que escribe el docente en ese campo, sumando el texto libre del campo entero y sus respuestas de texto. No cuentan las tablas repetibles (ya tienen límite por columna), ni el texto fijo de la plantilla, ni lo que escribió Coordinación. Las partes ocultas por «Mostrar solo si» no cuentan.
- S4. Al llegar: «No deja escribir más». El docente ve un contador («320 / 500»), que se pone en rojo al llegar; el editor no acepta más texto, tampoco al pegar. El servidor lo rechaza con un aviso claro en español si llega más.
- S5. Sin cambios en el Word y el PDF. Lo ya escrito por encima de un límite nuevo no se borra: el docente lo ve marcado y debe recortarlo para enviar (asunción: evita perder trabajo).
- S6. El asistente sabe ponerlo y explicarlo; documentación al día.

## Tasks

- [ ] T1 (S1–S6) Límite por campo en plantilla, editor del docente, servidor, asistente y documentación — delegado (contexto largo)

## Log

- L1 (usuario, 2026-11-17): pedido de S1. Análisis: hoy tienen límite las respuestas de texto (1–3000, «Más opciones»), los espacios vacíos (3000 fijo) y las columnas de tablas repetibles; el campo entero que escribe el docente solo tiene el tope general de 50 000 (`FreeText::MAX_CHARACTERS`).
- L2 (usuario): «Solo Administración» y «No deja escribir más».
- L3 (T1, writer delegado): `field_limits` en la definición (como `field_conditions`), `max_length` por campo en importar/exportar, vista previa, hojas y sílabo; `FieldLimit::count` y `fieldCharacters` cuentan lo mismo en cada capa. RED observado y luego GREEN en `FieldLimitTemplateTest`, `FieldLimitAnswersTest`, `TemplateAssistantTest` (límite), `TemplateAssistantScenariosTest`, `fieldLimits.test.ts`, `FieldLimit.test.ts` y `TemplateFieldLimit.test.ts`.
- L4 (T1): pendiente fuera de la superficie autorizada: `SendTemplateAssistantMessageRequest` (Presentation) no deja pasar `max_length` del borrador del editor al asistente, así que una propuesta hecha sobre un borrador con límites sin guardar los pierde. Falta una regla `draft.*.fields.*.max_length` y copiarlo en `draft()`.
