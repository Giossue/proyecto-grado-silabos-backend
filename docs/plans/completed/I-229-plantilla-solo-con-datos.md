# I-229 — Plantilla definida solo con datos

## Pedido del responsable, 2026-10-19

Centralizar la plantilla: nada del formato del sílabo en código duro. La plantilla vive en la base y un
desarrollador crea o cambia una plantilla escribiendo JSON, sin tocar código. El formato queda en el manual técnico
(`docs/architecture/template-json.md`). Decisión: ADR-0011.

## Problema (inventario 2026-10-19)

- Bloques con diseño en código y duplicado entre frontend y backend: `unidades`, `evaluacion`, `si_no`,
  `repetible` e `identificacion` (`bloque-identificacion.html` y casillas en código). Unidades ya no coincidía entre
  pantalla y Word.
- Reglas en código: 4 unidades, 16 semanas, horas de la asignatura, variante de evaluación sin prácticas.
- Catálogo de variables en `config/syllabus_variables.php`; 12 secciones obligatorias fijas; Biblioteca atada a la
  clave `bibliografia`; apariencia, papel (vista A4, Word carta), encabezado y pie en código; plantillas listas y
  título por defecto en código.
- Word y revisión rearmaban los bloques con el código vigente: cambiar código alteraba sílabos aprobados.
- Error encontrado: en el Word real los encabezados con fondo celeste salían con texto blanco.

## Hecho

- Backend:
  - `TemplateDefinition`, `TemplateStyle` y `Blocks/*` (`ValueSchema`, `BlockLayout`, `LayoutRules`, `Condition`,
    `BlockRules`, `BlockDefinition`).
  - `TemplateContext` (definición activa o congelada) y `TemplatePresentation`.
  - `DocumentBlock` queda como fachada.
- Base: migración `000223` agrega `definicion_plantilla` (`json`), la llena con la plantilla oficial más el título
  y diseño guardados, quita `mapeo_documento_plantilla` y mueve el trigger de «plantilla modificada».
- Plantilla oficial v2 (`resources/templates/silabo-oficial.json`) con documento, variables, cinco bloques, reglas,
  secciones obligatorias, biblioteca y plantillas listas.
- Importación y exportación v2 (el formato 1 del constructor se sigue aceptando). Los errores de la definición
  dicen la ruta exacta.
- La fotografía de la revisión (esquema 9) congela `template_definition`. Las anteriores usan la oficial con su
  título y diseño.
- Word: papel, apariencia, encabezado, pie, metadatos, texto de campo vacío y borde de tabla salen de la
  definición. El color de encabezado solo va a los `th` sin fondo propio.
- Validación al enviar, secciones obligatorias, biblioteca, variables y plantillas listas salen de la definición.
- Frontend:
  - `src/lib/blocks/*`: un intérprete que lee, llena y configura cualquier bloque, más `DocumentBlockContent`.
  - Se borran `DocumentUnits.vue`, `DocumentEvaluation.vue`, `DocumentIdentification.ts` y `blockLayouts.ts`.
  - La hoja usa el papel y el estilo de la definición.
  - Los avisos previos al envío salen de las reglas del JSON.
- `php artisan template:conformance <carpeta>` regenera los casos de conformidad del frontend.
- Documentación:
  - manual técnico;
  - ADR-0011;
  - `DECISIONS_STATUS.md`;
  - esquema de base, documentos, identificación y trazabilidad.

## Supuestos

- La tabla de Unidades oficial es la del Word (`UnitsTable`): la pantalla adopta esa.
- El papel oficial es el del Word (carta).
- Las reglas de negocio no cambian.
- La identificación sigue sin editarse desde el constructor visual: su diseño está en la definición, no en
  campos.

## Fuera de alcance

`FixedContent`, `TemplateDocumentDefaults` e `IdentificationCard::document` (el config `syllabus_template.php` ya se eliminó) solo
los usan migraciones antiguas y fotografías anteriores a los bloques; no definen la plantilla vigente.

## Verificación

- Backend:
  - `TemplateGoldenWordTest`: Word oficial idéntico salvo los colores corregidos.
  - `TemplateDefinitionTest`: bloque nuevo solo con JSON, errores con ruta, fotografía congelada y HTML de ida y
    vuelta.
  - `DocumentBlockTest`, `OfficialTemplateTest`, `WordRendererTest` y la suite completa.
- Frontend:
  - `blocks.test.ts`: conformidad exacta con el backend.
  - `DocumentBlock.test.ts`, `TeacherSyllabusDetailPage.test.ts` y la suite completa.
- Navegador local:
  - el constructor mide igual en vista y edición y ninguna fila cruza un corte de página;
  - el editor docente llena unidades e identificación con resumen de reglas en vivo;
  - el PDF con Gotenberg sale legible.
