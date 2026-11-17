# Editor de plantilla maduro (como Google Forms)

Objetivo: que el editor de la plantilla del Administrador (`/admin/plantilla`) se sienta maduro, no un MVP. Rama: main (commits locales, sin push).
Entrega: commits por unidad en backend y frontend (`proyecto-grado-silabos-frontend/`). Pruebas: Pest (backend), Vitest (frontend).

## Specs

- S1. Pedido: «o sea más cosas, porque mi idea era hacerlo similar a como google form o similar, quiero algo super maduro, no quiero un MVP en esto». Alcance elegido: las cinco partes (1 Lo básico, 2 Preguntas tipo Forms, 3 Cambios seguros, 4 y 5 Tablas y lógica).
- S2. Parte 1, lo básico del editor:
  - S2a. Insertar un dato del sistema desde la cinta («Insertar dato del sistema») y escribiendo «/», con el catálogo de `TemplateVariables::catalog()`.
  - S2b. Cada dato del sistema se ve con su nombre real (por ejemplo «Nombre de la facultad»), no como «Dato del sistema», también dentro de las celdas de la tabla de identificación, que hoy se ven vacías.
  - S2c. Duplicar un campo; mover un campo a otra sección; arrastrar y soltar campos y secciones.
  - S2d. Deshacer y rehacer también al agregar, borrar, mover o duplicar secciones y campos.
  - S2e. Vista previa del borrador sin guardar, también «Ver como docente».
- S3. Parte 2, respuestas tipo Forms. Modelo aceptado por el usuario («ok, me gusta»):
  - S3a. «creo que sería mejor para admin no? y que coordinador trabaje sobre eso»: el Administrador pone respuestas en la plantilla (dónde y de qué tipo, con opciones base) para todas las carreras. Vuelve el botón de respuestas al Administrador (revierte la decisión del 2026-11-11).
  - S3b. Se mantienen los tipos de hoy: texto libre, elegir una y elegir varias. Sin fecha ni texto corto (decisión del 2026-11-10) y sin número («borralo nomás», 2026-11-11, `odd/tasks/campo-carrera.md` L14). Corregido el 2026-11-16: decía cuatro tipos por error.
  - S3c. Por respuesta, el Administrador marca: obligatoria u opcional, texto de ayuda y límites (caracteres máximos en texto, cuántas elegir en varias); y si «elegir una» se muestra como lista o como desplegable.
  - S3d. Coordinación, en cada materia, ve las respuestas del Administrador ya puestas y puede ajustar sus opciones (agregar, quitar, cambiar), pero no cambiar el tipo ni borrarlas. Sigue pudiendo agregar sus propios espacios en los campos editables.
  - S3e. El docente solo responde; los límites y lo obligatorio se validan al guardar y al enviar, con avisos claros en español. PDF y Word muestran lo respondido.
- S4. Parte 3, cambios seguros:
  - S4a. Historial de versiones de la plantilla: cada guardado queda como versión, con quién y cuándo; se puede ver, comparar con la actual y restaurar.
  - S4b. Antes de guardar, aviso de qué sílabos se verán afectados.
  - S4c. Errores marcados en vivo en el campo o sección que los tiene, no solo al guardar.
- S5. Parte 4: editor visual de tablas repetibles (bloques como unidades, evaluación y bibliografía): crear y editar columnas, tipo de cada celda y límites de filas sin JSON.
- S6. Parte 5: lógica condicional desde la interfaz, por ejemplo «mostrar esto solo si respondió Sí».
- S8. Asistente de IA experto en la plantilla: «y si tienes en mente lo del agente? que sea experto en la edicion de la plantilla? porque eso es muy importante». Aprende cada capacidad nueva al agregarse, explica cómo hacer las cosas en el editor y se mide con casos de prueba.
- S7. Documentación al día (`docs/product/screens.md`, `docs/architecture/template-json.md`, `docs/quality/traceability.md`, openapi); la tesis solo con el visto bueno del usuario.

## Tasks

- [x] T1 (S2a, S2b) Datos del sistema en el editor del Administrador: cinta + «/», etiquetas reales en fichas y celdas — delegado (contexto largo). Frontend `0bb9be3`. RED 4/4 → GREEN 4/4 (`TemplateSheetField.test.ts`) + prueba del docente sin cambios (`DocumentBlock.test.ts`)
- [x] T2 (S2c) Duplicar, mover entre secciones y arrastrar y soltar campos y secciones — delegado junto con T3 (mismo archivo). Frontend `cc4a359`. RED 13/13 → GREEN (`TemplateVisualBuilderStructure.test.ts`)
- [x] T3 (S2d) Deshacer y rehacer de estructura — frontend `cc4a359` (`useStructureHistory.ts`, 4 pruebas). Risk: item 3 (deshacer/rehacer de la cinta y atajos globales del editor)
- [ ] T4 (S2e) Vista previa y «Ver como docente» del borrador
- [x] T5 (S3a–S3c, S3e) Respuestas del Administrador con obligatoria, ayuda, límites y desplegable; validación, docente, PDF/Word — Risk: item 1 (formato guardado del nodo de respuesta)
- [ ] T6 (S3d) Coordinación ajusta opciones de las respuestas del Administrador por materia — Risk: item 1
- [x] T7 (S4a) — delegado en paralelo con T1; verificación independiente PASS sin bloqueos (Risk 1). Commits: backend y frontend «feat(plantilla): historial de versiones». Backend RED 12 → GREEN 12 (`TemplateVersionHistoryTest`, `TemplateVersionDiffTest`); frontend RED → GREEN (`TemplateHistorySheet.test.ts`, `TemplateHistoryRestore.test.ts`); Historial de versiones: ver, comparar, restaurar — Risk: item 1 (migración nueva)
- [ ] T8 (S4b, S4c) Aviso de sílabos afectados y errores en vivo
- [ ] T9 (S5) Editor visual de tablas repetibles
- [ ] T10 (S6) Lógica condicional en la interfaz
- [ ] T11 (S7) Documentación y trazabilidad
- [ ] T12 (S8) Asistente experto: cada tarea le enseña lo nuevo; al final, casos de prueba amplios y ajuste hasta resolverlos

## Log

- L1 (usuario, 2026-11-16): «o sea más cosas, porque mi idea era hacerlo similar a como google form o similar, quiero algo super maduro, no quiero un MVP en esto». Antes: «creo que podemos seguir puliendo lo de la plantilla, qué crees?».
- L2: el usuario eligió las cinco partes. Para la parte 2 propuso «creo que sería mejor para admin no? y que coordinador trabaje sobre eso, qué te parece?»; se aceptó el modelo de capas de S3 con «ok, me gusta».
- L3: mapa del editor (explorador, 2026-11-16): `TemplateSheetField.vue` usa `FreeTextEditor` sin `variables` y sin barra; los bloques (`documentBlock`) y `keep_if` solo por JSON; sin historial, sin borrador/publicado, sin duplicar ni arrastrar; deshacer solo dentro del texto; «Ver como docente» solo de lo guardado.
- L4: T1 hecho. Queda abierto: en el modo de lectura de la plantilla (sin editar) las celdas del bloque y los datos del sistema siguen vacíos a propósito (pruebas previas lo fijan); revisar en T4 junto con la vista previa del borrador.
- L5: T2 y T3 hechos. Abiertos: tras deshacer una estructura, la historia de texto de TipTap del campo no se limpia; arrastrar solo se probó en jsdom (revisar en el navegador del Admin); un campo sin nombre duplicado queda sin nombre.
- L6: T7: el escritor restauraba solo secciones; el padre agregó `applyVersion` en el editor para restaurar también título y márgenes (RED 1 → GREEN 3 en `TemplateHistoryRestore.test.ts`). Se actualizaron AGENTS.md («no tiene versiones»), DECISIONS_STATUS.md, screens.md y database.md. Migración aplicada en la base local. Pendiente: regenerar `docs/generated/database-schema.md` (T11). Restaurar no recupera la definición global (bloques, variables, presets): el guardado del editor conserva la actual.
- L7: verificación independiente de T7: PASS. Backend completo 835 pasaron (`php -d memory_limit=1G vendor/bin/pest`; `php artisan test` se queda sin memoria también en la base), frontend 884 pasaron, typecheck, lint y contract:check pasan. Exportación idéntica byte a byte a la base. Avisos: los endpoints de estructura sueltos no guardan versión (el editor no los usa); la primera versión «Sistema» se crea al abrir el historial; restaurar no recupera lo cambiado solo por JSON (bloques, variables, presets).
- L8 (T5, escritor, 2026-11-16): conflicto de fuentes en S3b: dice «cuatro tipos (texto libre, número, elegir una, elegir varias)», pero el usuario pidió quitar Número el 2026-11-11 («borralo nomás», `campo-carrera.md` L14, commit `8ca40b69`). Se mantuvieron los tres tipos vigentes (`texto`, `una`, `varias`) y no se hizo el ajuste mínimo/máximo de número; falta que el usuario confirme si Número vuelve.
- L9 (T5, decisiones): ajustes en `attrs` del nodo `response`, opcionales y guardados solo si difieren del valor por defecto (`required` true, `help` "", `maxLength` 3000, `minChoices`/`maxChoices` sin límite, `display` `lista`); un documento sin ajustes queda idéntico. Catálogo único por capa: `ResponseKind::SETTINGS`/`COMMON_SETTINGS` y `KINDS[*]['settings']`; `RESPONSE_SETTING_DEFAULTS`/`RESPONSE_KINDS[*].settings` en `responseKinds.ts`. `sameQuestion`/`sameAnswer` comparan también los ajustes (el docente y Coordinación no los cambian). Al guardar: límites de lo respondido (una lista vacía se guarda como borrador). Al enviar: `pending` no cuenta las opcionales sin contestar; un campo cuyo único espacio es opcional no da `campo_obligatorio_faltante` (`FieldResponse::asksForAnswer`); nuevo aviso `respuesta_fuera_de_limite`. El botón de la cinta del Administrador vuelve con el cableado de antes de `be0b44f` (en `TemplateVisualBuilder.vue`, junto a `TemplateFormatTools`). Coordinación usa el mismo `TemplateResponseTool` («Espacio para el docente»), así que también ve «Más opciones». Mensajes nuevos: ver `docs/architecture/template-json.md` («Ajustes de cada respuesta»).
- L10 (T5, evidencia): backend RED 9 de 12 (`ResponseSettingsTemplateTest`, `ResponseSettingsAnswersTest`; los 3 que pasaban fijan lo de antes: obligatoria vacía bloquea, desplegable contestado se imprime, HTML) + `TemplateAssistantTest::test_the_assistant_places_responses_with_their_settings` → GREEN. Frontend RED (código de HEAD): 2 en `responseKinds.test.ts`, 3 en `TemplateResponseTool.test.ts`, 3 en `ResponseAnswer.test.ts`, 1 en `TemplateVisualBuilder.test.ts` → GREEN. Pest de Configuration, Unit/Configuration, AiAssistance y respuestas: 174 pasan; filtro `TemplateGoldenWordTest|ResponseKind|SubjectProfile|Submit|CareerField|SubjectResponseSetup|ConvocationAndDraft|FinalizedProcessFrozen`: 129 pasan (golden de Word sin tocar). PHPStan 0, Pint pasa. Frontend: typecheck, lint y contract:check pasan. openapi sin cambios (sin rutas nuevas). Asistente: guía de respuestas en `03-contenido-html.md`, ejemplos 13–15, 3 pedidos en `pruebas.txt`, escenario «Respuestas con ajustes».
- L11 (T5, abiertos): (a) `02-secciones-y-campos.md` del asistente sigue con `docente`/`coordinacion`/«Fijo» (desactualizado desde `campo-carrera`); los ejemplos viejos de `04-ejemplos.md` también dicen `docente`. (b) Un valor guardado que rompe un límite solo se alcanza editando la base: la validación lo marca (`respuesta_fuera_de_limite`), pero el envío arma la fotografía antes de validar (`SubmitSyllabus`, huella) y se corta con el error de ese valor; no se probó por HTTP. (c) La ayuda de pantalla (`ScreenHelpCatalog`, fuera de la superficie) no menciona el botón de respuestas del Administrador. (d) Revisar en el navegador del Admin el cuadro con «Más opciones».
- L12 (usuario, 2026-11-16): «ok y si tienes en mente lo del agente? que sea experto en la edicion de la plantilla? porque eso es muy importante». Se agregan S8 y T12.
- L13: verificación independiente de T5: PASS sin bloqueos. Backend completo 848 pasaron; frontend 893 pasaron; typecheck, lint y contract:check pasan. Plantilla oficial, flujo del docente y Word idénticos byte a byte a la base. Avisos: Coordinación también puede poner ajustes en sus propias respuestas (mismo componente); un valor fuera de límite metido a mano en la base da dos avisos.
