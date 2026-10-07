# I-195 Plantilla MVP con cuatro tipos de campo y registro único

Pedido del responsable, 2026-10-07: rehacer la plantilla del sílabo como MVP con solo
cuatro tipos de campo fijos, definidos en un único registro fácil de extender.

## Decisiones confirmadas

- Se conservan el título y los logos del sílabo.
- Las tablas del docente tienen filas fijas (p. ej. distribución de 16 semanas); el docente
  no agrega filas.
- Discapacidad y formación/experiencia pasan a ser campos de texto sueltos; la tabla oficial
  de identificación es un campo automático fijo (`IdentificationCard::grid`).

## Tipos

1. `texto`: lo escribe el docente.
2. `ficha`: lo escribe coordinación una vez por materia y se hereda al sílabo; config
   `{adjustable: bool}` («el docente puede ajustarlo»).
3. `tabla`: filas y columnas fijas (`columns`, `rows`) que llena el docente.
4. `automatico`: config `{source}`, sin valor guardado; se resuelve de datos maestros.

## Aplazado

Hoja horizontal/margen/letra por sección, hoja libre con cinta Insertar, marcas
Docente/Sí-No/Firma/Dato automático insertables, anchos por celda.

## Etapas

- [x] 0. Documentación: este plan, ADR-0009, `docs/architecture/field-types.md`, trazabilidad.
- [x] 1. Registro backend y los 4 tipos (aditivo; no cambia la plantilla actual ni migra).
- [x] 2. Persistencia y herencia por tipo (migración `000202`; con capa de compatibilidad, ver Deuda).
- [x] 3. API de plantilla simplificada (endpoints nuevos `structure/...`; ver Etapa 3).
- [x] 4. Snapshot v7 y render Word/PDF por tipo (ver Etapa 4).
- [ ] 5. Plantilla inicial.
- [ ] 6. Frontend: registro y editor del docente.
- [ ] 7. Frontend: constructor simple.
- [ ] 8. Limpieza del modelo anterior.

## Etapa 2: decisiones y deuda

- `definiciones_campo.tipo_definicion_campo` solo admite `texto|ficha|tabla|automatico`. La
  configuración por tipo va en `opciones_definicion_campo` (objeto JSON): POR VALIDAR si merece
  columna propia. En `ficha`, si falta `adjustable`, se toma de `editable_docente`.
- Filas antiguas: la migración las convierte sin borrar (`texto_libre` heredado de la ficha a
  `ficha`; `referencia_maestra` a `automatico`; el resto a `texto`) y guarda el tipo antiguo en
  `clase_anterior_definicion_campo`.
- `FieldDefinition::fieldType()` y `typeConfig()` leen del registro. `InheritMasterValues` usa
  `defaultValue` y `UpdateDraftField` usa `validateValue` para campos sin clase anterior;
  `SubjectProfileFields` filtra por tipo `ficha`.
- Deuda a quitar en la etapa 8: columna `clase_anterior_definicion_campo`, el atributo `tipo`
  que devuelve la clase anterior (`FieldDefinition::getAttribute/setAttribute/saving`),
  `hasLegacyClass()` y las rutas de compatibilidad en `UpdateDraftField` e `InheritMasterValues`.
  Las selecciones, número y fecha siguen existiendo solo por esa capa.

## Etapa 3: decisiones y deuda

- Endpoints nuevos bajo `admin/template/{template}/structure` (ver `field-types.md`); un campo es
  un bloque más su definición. Reutilizan `DeleteTemplateSection`, `DeleteTemplateBlock`,
  `ReorderTemplateSections`, `ReorderTemplateBlocks` y `SaveTemplateSection::update`; la creación y
  edición de campos va en `SaveTemplateStructure`.
- Se mantiene el bloqueo con proceso abierto (`assertTemplateEditable`); el aviso `purge_required`
  de la plantilla no existe hoy en estas rutas.
- `automatico` guarda `origen_maestro = source`; `TemplateStructureValidator` acepta `ficha` y
  `automatico`.
- Deuda etapa 8: borrar `SaveTemplateSection::create`, `SaveFieldDefinition`, `storeSection`,
  `storeField`, `updateField`, `reorderBlocks`, `destroyBlock` y sus rutas, requests y presets
  viejos, cuando el frontend (etapas 6-7) use solo `structure/...`.
- Fallan en HEAD, antes de esta etapa (compat de la etapa 2): `ConvocationAndDraftTest`
  `test_autosave_enforces_template_type_and_rejects_html_in_long_text` y
  `test_part_shown_only_when_another_matches_is_not_required_while_hidden_and_prints_empty`.

## Etapa 4: decisiones y deuda

- `FieldType` suma `toSnapshot(value, config, context)` (el automático resuelve `identification_card` con la ficha de datos maestros al enviar) y `toWord(target, field, WordRenderContext)`. Texto y ficha pintan el documento `FreeText`; tabla, una tabla de filas y columnas fijas (`FreeText::fixedTable`); automático, `IdentificationCard::document` (cuadrícula oficial `IdentificationCard::grid`). El Word sale del snapshot, nunca de datos vivos.
- `SyllabusSnapshot` pasa a `schema_version` 7: cada sección conserva `blocks` (respaldo) y suma `fields[]` planos con `definition_id`, `key`, `label`, `type`, `legacy`, `config` y valor resuelto. `restore` lee `fields[]` en v7 (omite el valor de los automáticos) y los bloques en v6 o anterior: los snapshots v6 locales se siguen leyendo y renderizando por la ruta vieja.
- Render: `SyllabusWordDocument` usa el recorrido nuevo (`WordFieldRenderer::renderTyped`) en una sección solo si todos sus campos son del modelo nuevo (`legacy = false`); si alguno conserva clase anterior, usa el render viejo (`TemplateDocumentWord` con `document` del bloque). La plantilla institucional actual sigue en la ruta vieja hasta la etapa 5. Título, logos, márgenes y metadatos no cambian; el PDF sale de Gotenberg a partir del mismo Word.
- Deuda etapa 8: quitar `blocks`/`document` del snapshot, la ruta vieja de `WordFieldRenderer::render`, la lectura de v6 en `restore` y el texto plano del PDF sin Gotenberg (`SyllabusDocumentContent` lee aún `blocks`; con el recorrido nuevo no muestra los campos automáticos). `FieldType` (dominio) depende de `WordRenderContext` (Documents): POR VALIDAR si se extrae una interfaz de escritor.
- Se conserva `renderer_version` (`phpword-docx-v3+text-pdf-v2`): el render de revisiones viejas no cambia.

## POR VALIDAR

- Formato exacto del valor de `tabla` (hoy: mapa `fila → columna → texto`).
- Lista de fuentes de `automatico` (hoy solo `identification_card`).
- Estrategia de migración de plantillas existentes (etapas 2 y 5).

## Decisión: sin campos condicionales en el MVP

La condición «mostrar si» dependía de los tipos `seleccion_unica` y `booleano`, retirados en la etapa 2. En el MVP los campos de discapacidad (tipo y adaptación) siempre se muestran; el docente los deja vacíos si no aplican. Se retiró la prueba de campos condicionales; el código de `FieldConditions` y `mostrar_si` se elimina en la etapa 8.
