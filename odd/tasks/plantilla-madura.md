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
  - S3b. Se mantienen los cuatro tipos (texto libre, número, elegir una, elegir varias); sin fecha ni texto corto (decisión del 2026-11-10).
  - S3c. Por respuesta, el Administrador marca: obligatoria u opcional, texto de ayuda y límites (caracteres máximos en texto, mínimo y máximo en número, cuántas elegir en varias); y si «elegir una» se muestra como lista o como desplegable.
  - S3d. Coordinación, en cada materia, ve las respuestas del Administrador ya puestas y puede ajustar sus opciones (agregar, quitar, cambiar), pero no cambiar el tipo ni borrarlas. Sigue pudiendo agregar sus propios espacios en los campos editables.
  - S3e. El docente solo responde; los límites y lo obligatorio se validan al guardar y al enviar, con avisos claros en español. PDF y Word muestran lo respondido.
- S4. Parte 3, cambios seguros:
  - S4a. Historial de versiones de la plantilla: cada guardado queda como versión, con quién y cuándo; se puede ver, comparar con la actual y restaurar.
  - S4b. Antes de guardar, aviso de qué sílabos se verán afectados.
  - S4c. Errores marcados en vivo en el campo o sección que los tiene, no solo al guardar.
- S5. Parte 4: editor visual de tablas repetibles (bloques como unidades, evaluación y bibliografía): crear y editar columnas, tipo de cada celda y límites de filas sin JSON.
- S6. Parte 5: lógica condicional desde la interfaz, por ejemplo «mostrar esto solo si respondió Sí».
- S7. Documentación al día (`docs/product/screens.md`, `docs/architecture/template-json.md`, `docs/quality/traceability.md`, openapi); la tesis solo con el visto bueno del usuario.

## Tasks

- [x] T1 (S2a, S2b) Datos del sistema en el editor del Administrador: cinta + «/», etiquetas reales en fichas y celdas — delegado (contexto largo). Frontend `0bb9be3`. RED 4/4 → GREEN 4/4 (`TemplateSheetField.test.ts`) + prueba del docente sin cambios (`DocumentBlock.test.ts`)
- [x] T2 (S2c) Duplicar, mover entre secciones y arrastrar y soltar campos y secciones — delegado junto con T3 (mismo archivo). Frontend `cc4a359`. RED 13/13 → GREEN (`TemplateVisualBuilderStructure.test.ts`)
- [x] T3 (S2d) Deshacer y rehacer de estructura — frontend `cc4a359` (`useStructureHistory.ts`, 4 pruebas). Risk: item 3 (deshacer/rehacer de la cinta y atajos globales del editor)
- [ ] T4 (S2e) Vista previa y «Ver como docente» del borrador
- [ ] T5 (S3a–S3c, S3e) Respuestas del Administrador con obligatoria, ayuda, límites y desplegable; validación, docente, PDF/Word — Risk: item 1 (formato guardado del nodo de respuesta)
- [ ] T6 (S3d) Coordinación ajusta opciones de las respuestas del Administrador por materia — Risk: item 1
- [x] T7 (S4a) — delegado en paralelo con T1; verificación independiente PASS sin bloqueos (Risk 1). Commits: backend y frontend «feat(plantilla): historial de versiones». Backend RED 12 → GREEN 12 (`TemplateVersionHistoryTest`, `TemplateVersionDiffTest`); frontend RED → GREEN (`TemplateHistorySheet.test.ts`, `TemplateHistoryRestore.test.ts`); Historial de versiones: ver, comparar, restaurar — Risk: item 1 (migración nueva)
- [ ] T8 (S4b, S4c) Aviso de sílabos afectados y errores en vivo
- [ ] T9 (S5) Editor visual de tablas repetibles
- [ ] T10 (S6) Lógica condicional en la interfaz
- [ ] T11 (S7) Documentación y trazabilidad

## Log

- L1 (usuario, 2026-11-16): «o sea más cosas, porque mi idea era hacerlo similar a como google form o similar, quiero algo super maduro, no quiero un MVP en esto». Antes: «creo que podemos seguir puliendo lo de la plantilla, qué crees?».
- L2: el usuario eligió las cinco partes. Para la parte 2 propuso «creo que sería mejor para admin no? y que coordinador trabaje sobre eso, qué te parece?»; se aceptó el modelo de capas de S3 con «ok, me gusta».
- L3: mapa del editor (explorador, 2026-11-16): `TemplateSheetField.vue` usa `FreeTextEditor` sin `variables` y sin barra; los bloques (`documentBlock`) y `keep_if` solo por JSON; sin historial, sin borrador/publicado, sin duplicar ni arrastrar; deshacer solo dentro del texto; «Ver como docente» solo de lo guardado.
- L4: T1 hecho. Queda abierto: en el modo de lectura de la plantilla (sin editar) las celdas del bloque y los datos del sistema siguen vacíos a propósito (pruebas previas lo fijan); revisar en T4 junto con la vista previa del borrador.
- L5: T2 y T3 hechos. Abiertos: tras deshacer una estructura, la historia de texto de TipTap del campo no se limpia; arrastrar solo se probó en jsdom (revisar en el navegador del Admin); un campo sin nombre duplicado queda sin nombre.
- L6: T7: el escritor restauraba solo secciones; el padre agregó `applyVersion` en el editor para restaurar también título y márgenes (RED 1 → GREEN 3 en `TemplateHistoryRestore.test.ts`). Se actualizaron AGENTS.md («no tiene versiones»), DECISIONS_STATUS.md, screens.md y database.md. Migración aplicada en la base local. Pendiente: regenerar `docs/generated/database-schema.md` (T11). Restaurar no recupera la definición global (bloques, variables, presets): el guardado del editor conserva la actual.
- L7: verificación independiente de T7: PASS. Backend completo 835 pasaron (`php -d memory_limit=1G vendor/bin/pest`; `php artisan test` se queda sin memoria también en la base), frontend 884 pasaron, typecheck, lint y contract:check pasan. Exportación idéntica byte a byte a la base. Avisos: los endpoints de estructura sueltos no guardan versión (el editor no los usa); la primera versión «Sistema» se crea al abrir el historial; restaurar no recupera lo cambiado solo por JSON (bloques, variables, presets).
