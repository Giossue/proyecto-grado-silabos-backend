# I-155 — Marcas en la hoja: dato automático, !Docente y @ProyectoCarrera

## Decisión del responsable, 2026-09-29

«¿Quién llena esta parte?» (I-151, I-154) se sustituye por marcas dentro de la hoja. Al
crear una parte, Administración solo elige su forma: párrafo, lista o tabla. Dentro de
la hoja, texto y celdas pueden mezclar:

| Marca | Quién la llena | Nodo del documento |
|---|---|---|
| Texto normal | Nadie: es fijo | `text` |
| **Dato automático** | El sistema, según lo declarado en el código (`config/syllabus_variables.php`) | `variable` institucional |
| **!Docente** | El docente, libremente | `field` / `column` (ya existentes) |
| **@ProyectoCarrera** | Coordinación, con el agente, al importar sus documentos | `variable` `curricular_<clave>` |

Respuestas del responsable:

1. El nombre de cada @ProyectoCarrera se hereda del nombre de la **sección**. Se puede
   cambiar. Dos marcas en la misma sección necesitan nombres distintos.
2. @ProyectoCarrera es obligatorio para Coordinación, igual que la malla. Si falta un
   dato de la plantilla para la carrera o para alguna materia programada, **no se
   puede abrir la convocatoria**. El docente nunca lo escribe.
3. Habilidades blandas (el docente elige de una lista del proyecto): queda como hoy, una
   pregunta !Docente de selección cuyas opciones vienen de un @ProyectoCarrera. Sin
   cambios en esta entrega. La lista usada como opciones también es obligatoria.

## Alcance y trazabilidad

- ADM-06, COR-16, apertura de convocatoria (I-46); RF-017..026; I-145, I-151, I-152.
- Sin cambios de esquema: los conceptos siguen en `conceptos_curriculares`. La relación
  concepto → sección se calcula leyendo la plantilla (nodos `variable` y
  `options_sources`), no se guarda.
- «La plantilla manda» se precisa: la importación busca los @ProyectoCarrera **usados**
  en la plantilla, con el nombre de la sección donde aparecen como contexto.

## Trabajo

### Backend
- [x] `ProjectDataUsage`: datos @ProyectoCarrera usados en la plantilla y sus secciones.
- [x] `POST admin/template/{template}/project-data` (`DeclareProjectData`): declara un
      @ProyectoCarrera (nombre, lista o texto, por asignatura o por carrera) y devuelve la
      variable; el mismo nombre con la misma forma se reutiliza.
- [x] Importación: `known_concepts` = datos usados, con `sections`; sin datos usados se
      rechaza. La propuesta guarda la forma del dato sin las secciones.
- [x] Apertura de convocatoria: `project_data_missing` en la preparación, `can_open`,
      `missingAcademicData` y paso `project_data` del tablero. Una lista o texto vacío no
      cuenta como confirmado.
- [x] Catálogo: sin campo nuevo; el prefijo `curricular_` ya distingue @ProyectoCarrera.
- [x] Retirado `content_type: automatic` (y `AutomaticPart`); las formas nuevas son
      párrafo, listas, tabla y texto libre (un párrafo, ya no una tabla de una celda).

### Frontend
- [x] Menú Insertar: Dato automático, !Docente, @ProyectoCarrera (existentes + «Nuevo
      dato…» con nombre de la sección). Fichas con prefijo (`!`, `@`) y color propio.
- [x] Atajos `{`, `!` y `@` en la hoja (`useSheetSuggestions`).
- [x] Párrafos y listas editables en la hoja («Editar en la hoja»), no solo tablas.
- [x] Crear o editar parte: solo la forma (`TemplatePartFormSelect`). Se retira
      `TemplatePartKindPicker`.
- [x] Formulario del docente: toda parte con hoja propia se llena sobre la hoja.
- [x] COR-16 muestra cada dato con su sección; el tablero enlaza el paso nuevo.

### Datos, documentación y verificación
- [x] Plantilla remota: RA, contenidos mínimos y conducta ética ya son hojas con marcas;
      no requieren cambios. Tras desplegar, la convocatoria exige esos datos confirmados.
- [x] `DECISIONS_STATUS.md`, `screens.md`, trazabilidad e ingesta curricular.
- [x] `composer verify` (399 pruebas); lint, typecheck, 222 pruebas de Vitest y build.

## Ajuste del responsable, 2026-09-29 (segunda vuelta)

- Formas de una parte: solo **Párrafo**, **Lista** (con viñetas) y **Tabla**. La parte
  empieza vacía; lo escrito sin marca es fijo y el docente no lo edita.
- Marcas sin diálogos: **Dato automático** (se elige de la lista), **!Docente** (texto
  libre), **?Docente** (el docente responde Sí o No; sirve de condición) y
  **@ProyectoCarrera**. El nombre interno se toma del contexto: texto previo en la misma
  línea o celda, primera celda de la fila, nombre de la parte o de la sección. Todo
  @ProyectoCarrera es por asignatura; en una lista se busca como lista, si no como texto.
- En una lista, !Docente y @ProyectoCarrera se vuelven elementos de la misma lista.
- En una fila que agrega el docente, !Docente es un dato de esa fila.
- Se retira por ahora «el docente elige de una lista» para partes nuevas; las habilidades
  se tratan como @ProyectoCarrera. Las partes existentes siguen funcionando.

### Trabajo de la segunda vuelta
- [x] Crear párrafo o lista con hoja vacía (`blank`) y sin pregunta inicial; ofrecer solo
      tres formas. La API conserva los tipos anteriores por compatibilidad.
- [x] `variable` con `listStyle`; listas del resolvedor y de la vista sin anidar.
- [x] Declaración automática de @ProyectoCarrera (mismo nombre con otra forma → nombre con
      sufijo «(lista)» o «(texto)»); alcance por asignatura por omisión.
- [x] Menú y atajos sin diálogos (`useSheetMarks`); fichas «!Docente», «?Docente»,
      «@ProyectoCarrera» con su nombre interno en la ayuda. Se retiran los diálogos de
      pregunta nueva y de dato nuevo.
- [x] Sí/No como dos botones en el formulario del docente, también en tablas.
- [x] `composer verify` (401 pruebas); lint, typecheck, 225 pruebas de Vitest y build.

### Ajustes posteriores, 2026-09-29
- [x] Cinta: «Editar» y «Propiedades»; un párrafo o una lista no muestran herramientas de
      celdas.
- [x] La forma de una parte no cambia después de crearla (pantalla y API).
