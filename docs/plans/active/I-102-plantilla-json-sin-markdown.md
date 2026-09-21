# I-102 — Plantilla del sílabo con diseño JSON

## Alcance

La plantilla institucional conserva su diseño visual como JSON ProseMirror/Tiptap;
Administración continúa editándolo con el constructor visual y no escribe JSON a mano.
Markdown deja de ser un tipo de campo de plantilla. Los datos antiguos se convierten a
`texto_largo` para no romper borradores, revisiones ni documentos históricos.

La definición inicial de la plantilla se mantiene como un seed idempotente y no como
una constante dentro de la acción HTTP. El seed no sobrescribe una plantilla existente.

## Trazabilidad

- RF-017..026; RN-009..012; CU-04; ADM-06; CP-F plantilla e inmutabilidad.
- I-32, I-56, I-58, I-63 e I-66.

## Trabajo

- [x] Mover la definición inicial a configuración/seed y materializar el diseño
      institucional inicial.
- [x] Migrar tipos `markdown` existentes a `texto_largo` y retirar su aceptación de
      la plantilla nueva.
- [x] Mantener lectura compatible de documentos JSON antiguos y actualizar el contrato
      visual TypeScript.
- [x] Actualizar pruebas y documentación del dominio.

## Verificación

Migración PostgreSQL, pruebas focalizadas de Configuration/Syllabus/Documents y del
seed: pasan (57 pruebas, 568 aserciones); `php -l`, Pint y `npm run typecheck` pasan.
