# I-187 — Ficha de asignatura: datos por materia que el sílabo hereda

> Implementación local en curso. La migración remota corresponde al deploy; esta tarea no se conecta a producción.

## Por qué

Los documentos de la carrera (`temp/documentos-carrera/`) ya traen casi todo lo que el docente copia y
pega en el sílabo: descripción, objetivos, resultados de aprendizaje, habilidades blandas,
bibliografía y herramientas TIC/IA. Hoy cada sílabo repite esos textos a mano.

Se guardan una sola vez por materia en una tabla nueva, `fichas_asignatura`. El coordinador la llena
y el sílabo la hereda al crearse.

Esta etapa **no** incluye subir documentos ni IA: el coordinador escribe la ficha a mano. La IA que
la rellena desde PDF/Excel es un plan posterior (I-188).

## Decisiones ya tomadas (no re-discutir)

- Una ficha por materia, reutilizada cada periodo.
- Edita la ficha **el coordinador de la carrera de esa materia** (y el administrador). El docente no.
- **Bibliografía: se puede usar, no es forzosa.** El sílabo nace con la bibliografía de la ficha ya
  puesta, pero el docente puede agregar, quitar o cambiar libros.
  Los demás campos de la ficha se heredan **solo lectura** (`heredado = true`, `editable_docente = false`),
  como la Identificación institucional.
- Al enviar el sílabo, la fotografía (`revisiones_silabo.fotografia`) guarda los valores del momento:
  si la ficha cambia después, los sílabos ya enviados no se alteran (ya funciona así por
  `SyllabusSnapshot`; solo hay que verificarlo).
- Los documentos de carrera, cuando lleguen, se guardan en `archivos`; no se crea tabla para ellos aquí.

## Datos que traen los documentos (para el seed y las pruebas)

- Excel `TIC, TAC, IA, BIBLIOGRAFÍA SW.xlsx`:
  - hoja `Bibliografia`: 43 materias; 38 con básica y complementaria, 5 vacías
    (p. ej. «Prácticas de servicio comunitario»). Cada entrada es una referencia APA con enlace.
    Un nombre trae salto de línea: «TRABAJO DE TITULACIÓN E INTEGRACIÓN\nCURRICULAR».
  - hoja `Inteligencia artificial` y hoja `Herramientas de aprendizaje`: herramientas por materia.
- Los nombres de materia coinciden con los de la malla (`Malla-Software-8-ciclos2.pdf`).
- Nada en los documentos dice si la bibliografía es obligatoria; por eso la decisión de arriba.

## Cambios

### 1. Base de datos

Migración `2026_10_04_000194_create_subject_profiles.php` (`down()` lanza `RuntimeException`, como las
migraciones 000184–000193). Tabla `fichas_asignatura`:

| Columna | Tipo | Nota |
|---|---|---|
| `ficha_asignatura_id` | uuid PK | PK con el nombre de la entidad (I-179) |
| `asignatura_id` | uuid, FK `asignaturas`, **unique**, `ON DELETE CASCADE` | una ficha por materia |
| `descripcion` | text null | |
| `objetivo_general` | text null | |
| `objetivos_especificos` | jsonb null | lista de textos |
| `resultados_aprendizaje` | jsonb null | lista de textos |
| `habilidades_blandas` | jsonb null | lista de `{nombre, definicion}` |
| `bibliografia_basica` | jsonb null | lista de textos |
| `bibliografia_complementaria` | jsonb null | lista de textos |
| `herramientas_tic` | jsonb null | lista de textos |
| `herramientas_ia` | jsonb null | lista de `{herramienta, caracteristica}` |
| `relacion_perfil_egreso` | text null | |
| `created_at`, `updated_at` | timestamps | solo si el resto de tablas del módulo los tiene |

- Sigue la convención de nombres en español de las demás tablas y de `MapsLegacyColumnNames`
  (alias `'id' => 'ficha_asignatura_id'`). Mira `Subject.php` como modelo de ejemplo.
- Tabla editable (no append-only): no necesita triggers de inmutabilidad.
- Actualiza `docs/architecture/production-database-schema.md`, `database.md` y, al final,
  `temp/tablas-bd-remota.md` (33 tablas).

### 2. Módulo Academic

- Modelo `SubjectProfile` (`app/Modules/Academic/Infrastructure/Persistence/Models/`) con relación
  `subject()`; `Subject::profile()` (`hasOne`).
- Acción `SaveSubjectProfile` (`Application/Actions/`): crea o actualiza la ficha de una materia.
  - Autoriza igual que las demás acciones de estructura: rol coordinador con `carrera_id` = la de
    la materia, o administrador (`AcademicStructurePermissions`, `ProcessLocks::assertCareerEditable`).
  - Registra auditoría con `RecordAuditEvent` (`academico.ficha_asignatura.guardada`).
  - Si hay sílabos **en borrador** de esa materia, no los actualiza solos (ver punto 4).
- Rutas bajo `CareerAcademicStructureController` o un controlador nuevo `SubjectProfileController`:
  `GET /api/.../asignaturas/{asignatura}/ficha` y `PUT` igual. Request con validación:
  listas de strings no vacíos, `habilidades_blandas[*].nombre` obligatorio.
  No pongas límite de caracteres (decisión del responsable, ver memoria «todo campo es obligatorio / sin límites»).
- Actualiza el contrato real `docs/architecture/openapi.json` y las pruebas de contrato.

### 3. Plantilla del sílabo

- Hoy las secciones «Descripción», «Objetivos», «Resultados de aprendizaje», «Habilidades blandas»,
  «Relación con el perfil de egreso» y «Bibliografía» son campos `texto_libre` editables
  (`SyllabusTemplateProvisioner`, `definiciones_campo`).
- Añade el origen `fichas_asignatura` a `definiciones_campo.origen_dato_definicion_campo`
  (hoy solo existe `asignaturas`) con la clave de la ficha que alimenta cada campo
  (columna nueva **no**: usa un valor tipo `fichas_asignatura.objetivo_general` en
  `origen_dato_definicion_campo`; revisa `FieldDefinition::origen_maestro`).
- `SyllabusTemplateProvisioner`: crea esos campos como `heredado = true`; solo bibliografía queda
  `heredado = true` **y** `editable_docente = true` (hereda un valor inicial, el docente lo ajusta).
  Revisa `UpdateDraftField` (línea ~35 rechaza editar si `heredado`): debe permitir editar un campo
  heredado cuando `editable_docente = true`. Revisa también `ValidateDraft` (línea ~77).
- `TemplateStructureValidator` y `SaveFieldDefinition` / `SaveFieldDefinitionRequest`: aceptar el
  origen nuevo y rechazar combinaciones inválidas.
- La plantilla remota ya existe; la migración o un comando de aprovisionamiento debe **convertir** los
  campos actuales sin borrar la plantilla (no perder `campos_plantilla`).
  Hay una sola plantilla activa: una migración de datos puntual está bien, con `down()` que lanza.

### 4. Herencia al crear el sílabo

- `InheritMasterValues::execute()` (`app/Modules/Syllabus/Application/InheritMasterValues.php`):
  añade el caso `fichas_asignatura` en el `match`, leyendo `$parallel->subject->profile`.
  Si la materia no tiene ficha, el valor queda `null` y `ValidateDraft` ya marca
  `maestro_obligatorio_faltante`: el mensaje debe decir «La asignatura no tiene ficha: pídala al
  coordinador».
- Los sílabos ya creados **no** se actualizan solos al cambiar la ficha. Añade una acción del
  coordinador «Aplicar ficha a los borradores» (reutiliza `ResetSyllabus`/`InheritMasterValues` solo
  sobre sílabos en borrador y sin cambios del docente en esos campos). Si complica, déjala fuera y
  documéntalo como pendiente.
- Verifica que `SyllabusSnapshot` copia los valores heredados a la fotografía (los envíos ya hechos
  no cambian si la ficha cambia después).
- El PDF (`Documents`) y la revisión de biblioteca (`BibliographySections`) leen la fotografía:
  no deben necesitar cambios, pero comprueba que la bibliografía heredada aparece igual que antes.

### 5. Frontend (`/home/giossue/Documentos/proyecto-grado-silabos-frontend`)

- En `CoordinationAcademicStructurePage.vue` (pestaña de asignaturas) añade «Ficha» por materia:
  hoja lateral (`Sheet`, como `ParallelCreationSheet.vue`) con los campos de la tabla.
  Listas editables (agregar/quitar elemento), textos largos para descripción y objetivo general.
  Usa los componentes shadcn ya existentes y los textos del `copy` en español.
- Indicador por materia: «Ficha completa / incompleta» y aviso en la preparación del periodo
  (`src/lib/periodPreparation.ts`) si hay materias sin ficha, para no generar sílabos vacíos.
- Editor del docente: los campos heredados solo lectura se ven como bloque no editable
  (patrón de Identificación institucional); bibliografía se ve editable con el valor inicial.
- API en `src/api/career-academics.ts`; tipos y pruebas Vitest.

### 6. Carga inicial (seed opcional)

- Comando `php artisan fichas:importar-bibliografia {archivo.xlsx}` que lee la hoja
  `Bibliografia` y llena `bibliografia_basica` / `bibliografia_complementaria` por nombre de
  materia (normalizar mayúsculas, tildes, espacios y saltos de línea). Informa las materias sin
  coincidencia y las que vienen vacías. No sobrescribe una ficha que el coordinador ya editó.
- Si no cabe en este plan, déjalo para I-188 junto con la IA.

## Fuera de alcance

- Subir Proyecto de carrera / Excel desde la interfaz y rellenar la ficha con IA (I-188).
- IA del docente para planificación de unidades (I-189).
- Bloques prearmados para el administrador de plantilla (I-190).
- Historial de versiones de la ficha (basta `eventos_auditoria`).

## Pruebas

- Backend (Pest/PHPUnit, `tests/Feature/Academic/`, `tests/Feature/Syllabus/`):
  - el coordinador de la carrera guarda y lee la ficha; el de otra carrera recibe 403; el docente 403;
  - una ficha por materia (unique);
  - un sílabo nuevo hereda los textos y la bibliografía de la ficha;
  - un campo heredado solo lectura rechaza edición del docente; la bibliografía sí la admite;
  - sin ficha, `ValidateDraft` devuelve `maestro_obligatorio_faltante`;
  - cambiar la ficha después del envío no altera la fotografía enviada;
  - `SpanishSchemaTest` y `SpanishModelColumnsTest` siguen pasando (ajusta el conteo de modelos si hace falta).
- Frontend: formulario de ficha, indicador de ficha incompleta, bloque heredado solo lectura.
- Ejecuta: suite backend completa, Pint, PHPStan; frontend `vitest`, `vue-tsc`, ESLint.

## Entregables y trazabilidad

- Fila nueva en `docs/quality/traceability.md` («Ficha de asignatura, herencia al sílabo (I-187)»).
- Ajusta `docs/architecture/production-database-schema.md`, `database.md`, `docs/architecture/openapi.json`.

## Alcance diferido de esta etapa

- La acción «Aplicar ficha a los borradores» queda pendiente. Un cambio de ficha solo afecta sílabos nuevos; los borradores existentes conservan sus valores hasta una acción explícita posterior. Requiere distinguir cambios del docente en bibliografía y respetar bloqueos de proceso.
- La carga desde Excel se traslada a I-188 junto con la importación documental. La ficha se ingresa manualmente en I-187.
- La revisión visual en dispositivos reales permanece asociada a PV-19.
- Mueve el plan a `docs/plans/completed/` cuando todo pase, con la evidencia de los comandos.
- Migración en la remota: la aplica el deploy; no te conectes a la remota.
