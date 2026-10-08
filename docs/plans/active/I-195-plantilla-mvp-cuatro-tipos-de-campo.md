# I-195 Plantilla MVP con cinco tipos de campo y registro único

Pedido del responsable, 2026-10-07: rehacer la plantilla del sílabo como MVP con solo
cinco tipos de campo (cuatro generales más `fijo`, ver Etapa 5b), definidos en un único registro fácil de extender.

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
- [x] 5. Plantilla inicial (ver Etapa 5).
- [x] 5b. Quinto tipo `fijo` y recuperación del contenido institucional (ver Etapa 5b).
- [x] 6. Frontend: registro y editor del docente (ver Etapa 6).
- [x] 7. Frontend: constructor simple (ver Etapa 7).
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
- Render: `SyllabusWordDocument` usa el recorrido nuevo (`WordFieldRenderer::renderTyped`) en una sección solo si todos sus campos son del modelo nuevo (`legacy = false`); si alguno conserva clase anterior, usa el render viejo (`TemplateDocumentWord` con `document` del bloque). La plantilla oficial provisionada (etapa 5) ya usa solo la ruta nueva. Título, logos, márgenes y metadatos no cambian; el PDF sale de Gotenberg a partir del mismo Word.
- Deuda etapa 8: quitar `blocks`/`document` del snapshot, la ruta vieja de `WordFieldRenderer::render`, la lectura de v6 en `restore` y el texto plano del PDF sin Gotenberg (`SyllabusDocumentContent` lee aún `blocks`; con el recorrido nuevo no muestra los campos automáticos). `FieldType` (dominio) depende de `WordRenderContext` (Documents): POR VALIDAR si se extrae una interfaz de escritor.
- Se conserva `renderer_version` (`phpword-docx-v3+text-pdf-v2`): el render de revisiones viejas no cambia.

## Etapa 5: decisiones y deuda

- `config/syllabus_template.php` `baseline` define `[sección, título, [[clave, etiqueta, tipo, config]]]`; el provisionador usa el registro (`normalizeConfig`) y las mismas columnas de herencia que `SaveTemplateStructure`. Planificación = `tabla` con 16 filas fijas (`semana_1`..`semana_16`) y 8 columnas; identificación = `automatico` + `discapacidad_tipo`, `discapacidad_adaptacion` y `formacion_experiencia` como `texto`; herramientas TIC se agrega como `ficha` en «Habilidades blandas».
- Decisión tomada (ver Etapa 5b): evaluación, conducta ética y firmas son campos `fijo` (quinto tipo).
- `formacion_experiencia` ya no se hereda del perfil del docente: lo escribe en cada sílabo. Se retiraron las pruebas de formación desde el perfil, de contenido inicial (`initial_content`) y de campos fijos/compactos/condicionales sobre la plantilla oficial.
- Arreglos de pasada: `UpdateDraftFieldRequest` validaba solo clases anteriores (prohibía el valor de los tipos nuevos); `ValidateDraft` pide valor por tipo y no exige el automático; `SaveStructureFieldRequest` no exige `config` al editar sin enviarla.
- Deuda etapa 8 (no se pudo borrar sin romper la API anterior): `SyllabusTemplateCatalog`, `catalog` e `identification_inputs` de la config (los usan `SaveFieldDefinition`, `SaveTemplateSection` y `TemplateController`), `TemplateDocumentDefaults::identification/evaluationIndicators/gradingScale/signatures/fixedPlaceholder`, `initial_content` en `InheritMasterValues`, ramas `institutional`/`fixed` de `SaveTemplateDocument` y `FieldConditions`. La API de documento por bloque (`blocks/{block}/document`) rechaza los bloques de la plantilla nueva (su documento usa `kind: texto_libre`).
- Remota: sigue vacía; «Crear plantilla» (`createEmpty`) + «Aplicar formato oficial» (`format`) y el seeder usan el mismo `populateOfficialFormat`.

## POR VALIDAR

- Formato exacto del valor de `tabla` (hoy: mapa `fila → columna → texto`).
- Lista de fuentes de `automatico` (hoy solo `identification_card`).
- Estrategia de migración de plantillas existentes (etapas 2 y 5).

## Decisión: sin campos condicionales en el MVP

La condición «mostrar si» dependía de los tipos `seleccion_unica` y `booleano`, retirados en la etapa 2. En el MVP los campos de discapacidad (tipo y adaptación) siempre se muestran; el docente los deja vacíos si no aplican. Se retiró la prueba de campos condicionales; el código de `FieldConditions` y `mostrar_si` se elimina en la etapa 8.

## Etapa 5b: quinto tipo `fijo` (texto fijo)

- Excepción justificada a «cuatro tipos»: la UEB exige contenido institucional idéntico en todos los sílabos y que el docente no edita (indicadores y escala de evaluación, conducta ética, firmas). Administración lo escribe una vez en la plantilla. ADR-0009 lleva la enmienda.
- `FixedFieldType` (`fijo`): `config.content` = documento `FreeText` (párrafos, listas, tablas). `defaultValue` y `toSnapshot` devuelven ese contenido; `teacherEditable` falso; no guarda valor por sílabo (`validateValue` rechaza cualquier valor; `UpdateDraftField` responde 422; `ValidateDraft` no lo exige; `restore` no lo escribe). Decisión: el contenido NO puede quedar vacío (`config.content` obligatorio, error `config.content`): un texto fijo vacío no aporta y ocultaría un olvido de Administración.
- Migración nueva `2026_10_07_000203_allow_fixed_field_type` (CHECK con cinco tipos). `SaveTemplateStructure` y el provisionador marcan `fijo` como no heredado y no editable por el docente. `TemplateStructureValidator` no cambia (un campo no heredado sin origen ya es válido).
- Plantilla oficial: `indicadores_evaluacion`, `componentes_evaluacion`, `conducta_etica` y `firmas` son `fijo` (`FixedContent::official`). Recuperado: tablas de indicadores (puntos y ponderación por parcial) y escala de valoración UEB, tomadas de `TemplateDocumentDefaults` y normalizadas a `FreeText`.
- No se pudo conservar: (1) el texto de conducta ética nunca existió en el código (era un marcador «Escriba aquí el contenido fijo»); resuelto después con el texto del PDF (ver «Decisión 2026-10-07 (II)»); (2) las firmas usaban variables insertables (`firma_docente`, `nombre_docente`, fechas, firma electrónica de coordinación y biblioteca) que `FreeText` no admite; quedan como tabla en blanco Elaborado/Revisado/Aprobado por × Nombre/Cargo/Firma, sin firma electrónica ni fechas automáticas; (3) color de encabezado y centrado de las tablas antiguas (FreeText solo guarda negrita y combinación de celdas).
- Deuda etapa 8: `TemplateDocumentDefaults::evaluationIndicators` y `gradingScale` ya no se pueden borrar; las usa `FixedContent`. La firma electrónica sobre el documento (`SignatureAnchor`) no está enlazada al contenido fijo.

## Etapa 6: frontend, registro y vistas del docente y del revisor

- `src/components/domain/fieldTypes/registry.ts` (frontend) mapea `texto|ficha|tabla|automatico|fijo` a `{label, icon, EditorComponent, ViewComponent, defaultConfig}` más `teacherEditable(config)` e `isEmpty`. Un componente `*FieldEditor.vue` (docente) y `*FieldView.vue` (lectura/revisión) por tipo; `FieldView.vue` es la tarjeta de solo lectura que usan las tres pantallas.
- Paridad: `registry.test.ts` fija `BACKEND_FIELD_TYPES` (lista mantenida a mano); `FieldTypesTest::test_registry_resolves_the_five_configured_types` del backend avisa en su comentario. Si se suma un tipo, fallan ambas pruebas hasta actualizar el registro.
- API del docente: cada sección suma `fields[]` planos (`type`, `config`, `legacy`, valor resuelto por el registro: la tabla trae sus filas vacías, el fijo su contenido, el automático los datos del sílabo; `document` con la cuadrícula oficial `IdentificationCard::document`). `blocks` se conserva por compatibilidad. En la API de revisión, `ReviewController` agrega `document` a los campos automáticos de la fotografía; Biblioteca y Coordinación ya recibían `fields[]` v7.
- `TeacherSyllabusDetailPage`, `CoordinationReviewDetailPage` y `LibraryReviewDetailPage` recorren `section.fields` (en fotografías v6, `sectionFields` cae a los bloques) y usan el componente del tipo. `SubjectProfileSheet` usa el editor de texto del registro.
- Deuda: no se tocó `TemplateVisualBuilder`/`AdminTemplatePage` (etapa 7). Las pantallas ya no pintan campos de la clase anterior (selección, número, fecha, booleano) ni bloques con diseño: se muestran como texto de solo lectura; los campos condicionales (`mostrar_si`) ya no se aplican en el docente. `CoordinationReviewComparisonPage` sigue leyendo `blocks` (etapa 8). Las fotografías v6 con bloques de diseño se ven como lista plana de campos. Falta revisión visual en navegador.

## Etapa 7: frontend, constructor simple de plantilla

- `AdminTemplatePage` tiene pestañas Constructor y Vista previa (sin modo «Editar» ni hoja). `TemplateVisualBuilder.vue` (reescrito) lista secciones numeradas con sus campos y usa solo `api/template-structure.ts` (`structure/...`); tras cada cambio recarga la plantilla. `TemplateSectionDialog` («Nueva sección» en dos pasos y renombrar), `TemplateFieldDialog` («Agregar campo» y «Propiedades del campo») y `TemplateFieldForm` (selector de tipo, nombre opcional, ayuda y configuración). `TemplatePreview` pinta el sílabo con las `*View.vue` del registro.
- Registro del frontend: cada tipo suma `adminLabel`, `adminHint`, `BuilderComponent` (`*FieldBuilder.vue`) y `validateConfig`; el selector de tipo sale del registro. Las claves de secciones y campos se generan del nombre (`uniqueKey`); un campo sin nombre usa `campo`, `campo_2`...
- Se eliminaron `TemplateTableEditor/Designer/InlineNode`, `TemplateInsertPopover`, `template-table/*`, `TemplateBlockCreator`, `TemplateFieldCreator/Actions`, `TemplateSectionActions`, `TemplateDocumentView`, `templateDocumentInput/Render`, `TemplateIconPopover`, `TemplateToolbarSelect`, `lib/templateParts`, `lib/templatePreview`, `lib/wordTableResize` y sus pruebas. «Personalizar plantilla» queda solo con el logo.
- Deuda: `lib/templateDocument.ts` (tipos de documento con marcas) y `TemplateTitleActions` (usa `PATCH .../title` de la API anterior y el evento `silabos:template-changed` de `ApiForm`/`apiVisit`) siguen vivos; `state/templateEditor.ts` ya no lo abre nadie (el layout lo lee; quitar en etapa 8). No hay recuperación de «proceso abierto» más allá del bloqueo y del mensaje del servidor. Sin revisión visual en navegador.

- Ajuste posterior a la etapa 7: el constructor y la vista previa se dibujan como hoja A4 (clases `template-stage`/`template-sheet` en `styles.css`) sobre fondo gris, con logos, título, secciones «1. Nombre», subtítulos «1.1 Nombre» solo con nombre, vista de cada tipo (`*View.vue`) y etiqueta del tipo. Los controles (subir, bajar, renombrar, propiedades, borrar) flotan sobre lo seleccionado; «Nueva sección» y «Cargar formato oficial UEB» viven en la hoja, también vacía. Sin revisión visual en navegador.
- Etapa 8: se quitaron la pestaña «Vista previa» (`TemplatePreview`) y «Personalizar plantilla»; la pantalla es solo la hoja. El logo de la universidad se cambia sobre la hoja (`TemplateLogoControl`, botón «Cambiar logo», mismo `storeLogo`, toast y estado de carga). Estilo oficial: título azul (`--document-official-blue`) y Arial 10 pt justificado. Una corrección posterior retiró el recuadro interior del contenido: el contorno discontinuo del campo basta en el constructor y las tablas conservan sus propios bordes. Sin revisión visual en navegador.

## Plantilla oficial fiel al PDF y estilo Arial (2026-10-07)

La solicitud posterior del responsable devuelve la vista previa como un modo global
de las mismas hojas, mediante un botón de ojo en la esquina superior derecha de cada
una; no vuelve la pestaña `TemplatePreview`. El constructor y la consulta comparten
`TemplateFieldContent`; la consulta oculta las herramientas, contornos y etiquetas
de tipo. Sigue disponible con la edición bloqueada. Evidencia y límites en
`docs/plans/completed/FE-PREVIEW-2026-10-07-ojo-por-hoja.md`.

- Revisado el PDF real (10 páginas, `temp/silabo-ejemplo`). `config/syllabus_template.php` reproduce sus 12
  secciones con sus títulos exactos; los campos únicos de una sección no llevan nombre (sin subtítulo);
  los subtítulos con nombre llevan su numeración en el propio nombre (7.1 a 7.5) y ya no se numeran solos
  (hoja y Word). Metodología pasa a cinco textos del docente (7.1-7.5; `herramientas_tic` = 7.4, ya no ficha).
  Bibliografía: «Bibliografía Básica» + «Complementaria». Evaluación: indicadores y «ESCALA DE VALORACIÓN» `fijo`.
- Identificación: ver `docs/product/identificacion-institucional.md`. Los tres textos del docente se inyectan en la tabla; la duplicación en revisión se resolvió en «Catálogo de diseños oficiales» (abajo).
- Letra Arial y título 14 pt en Word y hoja; la cabecera de la página usa el ancho de la hoja; se quitó el botón «Cargar formato oficial UEB» de la cabecera (queda el de la hoja vacía); desbordes de la hoja corregidos.
- No cabe en los cinco tipos (pendiente, no inventado): planificación por unidades (tabla de cada unidad con
  «Unidad No.», nombre, resultados, semanas y totales de horas por unidad; hoy es una tabla de 16 semanas);
  tabla de contribución al perfil de egreso (ALTA/MEDIA/BAJA y resultados de la carrera; hoy es una ficha de texto);
  tablas de bibliografía con columnas Autor/Título/Año/Ciudad/Editorial/ISBN/Código (hoy ficha de texto);
  firmas con QR/firma electrónica y nombres y fechas llenados solos; colores de cabecera distintos por tabla en `fijo`;
  hoja horizontal para la planificación. El texto real de conducta ética ya no es marcador: ver abajo.
- Sin verificación visual en navegador ni apertura del Word/PDF reales.

## Formato oficial del Word

`TemplateAppearance` fija los valores oficiales (Arial 10 pt, justificado, título azul UEB en
negrita, margen 2,5 cm vertical) e ignora los guardados; los campos de texto, ficha y fijo salen en un
recuadro de borde azul. Sin migración (la apariencia guardada ya no se usa). Pruebas:
`WordRendererTest::test_docx_follows_the_official_format`. Pendiente: papel A4 y comprobar el PDF real.

## Decisión 2026-10-07 (II): se mantiene el rediseño y se añade el catálogo de diseños oficiales

El responsable decidió no volver al modelo de hoja libre: se parte de lo que hay (cinco tipos de campo,
constructor sobre hoja A4, plantilla oficial provisionada, Word por tipo) y se mejora. La restauración
anterior (`restaurar-plantilla-libre`) quedó cancelada y no se usa. Hecho:

- **Catálogo de diseños oficiales** (`config/syllabus_template.php` `designs` + `SectionDesigns`): doce secciones ya
  armadas fieles al PDF (identificación, descripción, objetivos, resultados, habilidades blandas, planificación,
  metodología, evaluación, perfil de egreso, conducta ética, bibliografía, firmas), cada una con sus campos y tipos.
  La plantilla oficial provisionada (`baseline`) es la lista ordenada de esas mismas claves, así que el catálogo y el
  botón «Cargar formato oficial UEB» no pueden diverger. `GET admin/template/{id}` entrega `sectionDesigns`
  (clave, título, resumen, campos y `in_use`); `POST structure/sections` acepta `design` (con `title` y `position`
  opcionales) y crea la sección con todos sus campos en una transacción. Un diseño se agrega una vez: si la plantilla ya
  tiene su clave de sección o alguna clave de campo, responde 422 en `design`. «En blanco» sigue igual (`key` y `title`).
  Cambiar el formato oficial = editar ese archivo y `FixedContent` (nada de interfaz).
- **Tipos técnicos fuera del selector**: `FieldType::manual()` (texto, ficha y tabla sí; automático y fijo no) y
  `FieldTypeRegistry::manualKeys()`; `SaveStructureFieldRequest` rechaza crear a mano `automatico`/`fijo` (422 en `type`).
  El registro conserva los cinco y siguen editables (nombre, ayuda, contenido del texto fijo) los que vienen de un diseño. El
  frontend lo refleja con `manual` en `registry.ts` y ya no tiene el selector «Dato que se completa solo».
- **Identificación solo con la tabla**: los tres textos del docente son `texto` con `config.inside = identification_card`
  (`TextFieldType::INSIDE`). Siguen siendo campos con valor propio (los llena el docente, obligatorios, entran en la
  fotografía y se inyectan en la tabla por `IdentificationCard::INPUT_KEYS`), pero la hoja del constructor, las revisiones
  de Coordinación y Biblioteca (`sectionFields`) y el Word no los repiten: solo se ve la tabla. En la pantalla del docente se
  piden justo debajo de la tabla, con una nota de que se imprimen dentro de ella. Editar directamente las celdas de la tabla
  en la pantalla del docente queda pendiente (la cuadrícula es HTML generado por el servidor).
- **Contenido real recuperado** (ver Etapa 5b, ahora completada): conducta y comportamiento ético, transcrito del PDF de
  referencia (páginas 8-9) y contrastado con la versión que existió en la base remota en `SQL_REMOTA_I-168.sql`
  (la del PDF es más completa: suma puntualidad, videoconferencia y registro de asistencia, y separa lo inadmisible en
  subpuntos); indicadores y escala de valoración con las palabras del PDF (puntos y ponderación por parcial, escala A-E con
  «Igual a 10 o mayor o igual que 9»…); cuadro de firmas del PDF (Firma / Nombre y cargo / Fecha de entrega y de revisión);
  etiquetas de la tabla de identificación («Unidad Básica / Profesional / de Titulación»). La BD local (`silabos_ueb`)
  solo conserva el contenido genérico de `TemplateDocumentDefaults` (indicadores, escala y firmas con variables); no había
  texto de ética en ella ni en el historial de git más allá de `SQL_REMOTA_I-168.sql`.
- **Word**: las listas anidadas del contenido fijo salen con sangría por nivel (`TemplateDocumentWord::$listDepth`, tres niveles
  de viñeta y de numeración).
- **Frontend**: «Nueva sección» abre un catálogo («Usar un diseño oficial», con lo que incluye cada diseño y «Ya está en la
  plantilla» si corresponde) y «En blanco» (nombre y primer campo). La hoja va directo sobre el fondo de la página y es papel
  claro también en modo oscuro: `.template-sheet` redeclara los tokens claros (`:root, .theme-light, .template-sheet`), así sus
  controles, textos y botones se ven bien en ambos temas sin colores sueltos. El papel blanco y el texto negro salen de
  `--document-paper` y `--document-text`, que fija `initializeDocumentPalette` (paleta documental aislada).
- **Sin cambio de tipo o de modelo**: no hay migraciones. La remota sigue vacía: tras el despliegue basta «Cargar formato oficial UEB».
- **No cupo y queda anotado**: planificación por unidades con una tabla por unidad (el número de semanas por unidad cambia
  de una asignatura a otra y `tabla` tiene filas fijas: se mantiene la tabla de 16 semanas), contribución ALTA/MEDIA/BAJA con
  los resultados de la carrera, tablas de bibliografía por columnas (filas variables), hoja horizontal para la planificación,
  firma electrónica con QR y nombres/fechas que se llenen solos.
- **No verificado**: el aspecto real en navegador (claro y oscuro) y la apertura del Word y del PDF; pruebas automáticas
  solamente.
