# I-210 — Plantilla simple: un documento, secciones y campos sin tipo

## Pedido del responsable, 2026-10-09

La plantilla actual resulta complicada. La plantilla es **todo el documento** del sílabo de
ejemplo (`temp/silabo-ejemplo/SILABOCALIDAD_SWPAO_II_2626_signed-signed (2).pdf`). Desde ahí,
Administración solo agrega, quita, ordena y edita secciones y campos. Un campo no tiene «tipo»
(texto, tabla, lista...): es un espacio con editor libre donde se puede escribir texto, listas y
tablas. No hay tablas especiales por sección: no escala.

## Modelo

- **Plantilla**: una sola, sin versiones (I-32). Título y logos fijos (no son campos).
- **Sección**: título y orden; contiene campos. Puede ser horizontal (hoja apaisada).
- **Campo**: título, ayuda opcional, **quién lo llena** y contenido (documento libre).
  - **Docente**: el docente escribe sobre el contenido inicial que puso Administración.
  - **Coordinación**: se llena una vez por materia en la ficha y se hereda; el docente no puede
    modificarlo (aclarado por el responsable, 2026-10-09).
  - **Institucional**: contenido definido en la plantilla; solo lectura para el docente; puede
    llevar variables.
- **Variables**: datos del sistema que Administración inserta dentro del contenido (facultad,
  carrera, modalidad, campus, asignatura, período, ciclo, paralelo, jornada, código, prerrequisitos,
  correquisitos, unidad de organización curricular, horas y créditos, docente, correo, firmas y
  fechas). Ya existen como `template_variables`; solo deja de haber tabla automática especial.

Reemplaza los cinco tipos (`texto`, `ficha`, `tabla`, `automatico`, `fijo`, ADR-0009).

## Plantilla oficial (contenido de partida, tomado del PDF)

| # | Sección | Campos | Quién llena |
|---|---|---|---|
| 1 | Identificación institucional | Datos de identificación (tabla con variables) | Institucional |
|   |  | Estudiantes con discapacidad | Docente |
|   |  | Formación y experiencia académica – investigativa | Docente |
| 2 | Descripción de la asignatura | Descripción | Coordinación |
| 3 | Objetivo(s) de la asignatura | Objetivo general y objetivos específicos | Coordinación |
| 4 | Resultados de aprendizaje | Resultados de aprendizaje | Coordinación |
| 5 | Habilidades blandas | Habilidades blandas | Coordinación |
| 6 | Distribución y planificación de las unidades curriculares (horizontal) | Unidades (una tabla por unidad: nombre, resultados, 16 semanas con horas ACD/APE/AA, actividades y evaluación) | Docente |
| 7 | Metodología y ambientes de aprendizaje | 7.1 Métodos, 7.2 Técnicas, 7.3 Recursos, 7.4 Herramientas, 7.5 Ambientes | Docente |
| 8 | Evaluación de los aprendizajes | Indicadores por parcial y escala de valoración | Institucional |
| 9 | Relación con el perfil de egreso | Resultados de la asignatura frente a los de la carrera | Coordinación |
| 10 | Conducta y comportamiento ético | Texto institucional | Institucional |
| 11 | Bibliografía | Básica y complementaria | Docente |
| — | Revisión y aprobación | Firmas, nombres y fechas de docente y coordinación | Institucional |

## Decisiones tomadas

- Una sola opción «quién lo llena» por campo (confirmado).
- Sin tablas especiales por sección: tablas, listas y texto salen del mismo editor libre
  (confirmado).
- Se reemplaza la plantilla y los sílabos en curso por la del PDF; los aprobados se conservan
  (confirmado, mismo criterio que I-118).

## Validaciones resueltas por el responsable

- Se usa el reparto propuesto en la tabla del plan.
- El docente edita tablas libremente y puede agregar filas.
- El docente escribe los totales de horas, sin cálculo ni validación.
- La bibliografía es docente y deja de importarse desde la ficha.

## Excepción de conservación para esta migración

Solo la migración que carga la plantilla del PDF conserva los sílabos aprobados,
la evidencia aprobada vinculada tras reapertura y los procesos cerrados, como I-118.
Los cambios futuros de plantilla mantienen I-188: no se revierte su reinicio al reanudar.
`domain-model.md` y `syllabus-lifecycle.md` se restauran a su estado previo.

## Etapas (implementadas localmente)

1. Documentación: ADR que reemplaza ADR-0009, `field-types.md`, especificación y trazabilidad.
2. Backend: campo con `quien_llena` y contenido documento; migración y reinicio de sílabos;
   herencia de ficha, snapshot y Word/PDF por documento; retiro de tipos y compatibilidad.
3. Frontend: constructor (agregar sección/campo, elegir quién lo llena, editar contenido) y
   editor del docente.
4. Plantilla oficial cargada desde `config/syllabus_template.php`.

## Registro de ejecución — 2026-10-09

- Alcance: RF-017..026, RN-009..016, CU-04/CU-07, ADM-06, COR ficha, DOC-02/DOC-04; CP-F plantilla, borrador y documentos. PV-01/02/07/16/19 no se cierran por esta tarea.
- Etapa 1: ADR-0010 reemplaza ADR-0009; documentación del modelo y especificación actualizadas. Puertas ejecutadas antes del commit: `npm test` pasa (360 pruebas, 1 omitida); `composer verify` bloqueado por el SQL ya versionado `SQL_REMOTA_I-168.sql`. El diagnóstico `composer test` pasa Pint y encuentra 19 errores preexistentes de Larastan; no llega a la suite PHP. Se conserva el archivo ajeno a I-210. No se declara verificación backend satisfactoria.
- Se consultó al responsable el reparto propuesto de responsables, filas libres y totales manuales. Hasta respuesta, no se implementan reglas dependientes de esas decisiones.
- Repositorios inicialmente limpios. Commits por etapa autorizados; no hay autorización de push/despliegue. No se modifica producción.

### Bloqueo antes de la etapa 2

La migración solicitada debe cargar la plantilla oficial. Esa carga obliga a asignar
responsables a las secciones cuyo reparto la propia fuente marca como propuesta;
asignarlos determinaría permisos y datos de ficha. El editor de planificación también
depende de la confirmación de filas libres y totales manuales. La consulta permanece
pendiente: no se convierte el reparto propuesto en permisos efectivos ni se inicia una
migración parcial que no cargue el formato requerido. Etapas 2–4 pendientes.

Las fuentes anteriores (`domain-model.md`, `syllabus-lifecycle.md`) describían el reinicio
de aprobados por I-188. La interpretación inicial de I-210 extendió esa conservación a cambios futuros.
El responsable corrigió esa interpretación: la excepción solo aplica a la migración
y las fuentes de I-188 se restauran.

Diagnóstico adicional del estado inicial: `php artisan test` ejecutó 418 pruebas,
417 satisfactorias y 1 fallo preexistente en el conteo de métricas de
`SyllabusProcessTest::test_dashboards_expose_the_shared_deadline_and_allow_coordination_to_open_its_scope`.
No se modificó código ni se ejecutaron migraciones en producción.

### Continuación autorizada

El responsable resolvió los tres pendientes, limitó la conservación a esta migración
y autorizó las etapas 2–4. El bloqueo anterior queda resuelto. Se conserva sin tocar
`SQL_REMOTA_I-168.sql` y los errores previos de Larastan. No hay push ni despliegue.

### Etapa 2 — Backend

- Campos con responsable y documento; se retiran tipos, registro, opciones y condiciones
  de tipos, así como sus rutas anteriores. El título y los logos no admiten edición
  desde la plantilla. Herencia de ficha solo para Coordinación; bibliografía y
  metodología docentes. Contrato OpenAPI regenerado desde Artisan.
- Migración nueva `000219`, sin cambios en migraciones anteriores. El formato usa
  `config/syllabus_template.php`. Si aún no existe una plantilla, la instalación
  inicial usa el provisionador/seeder con esa misma configuración.
- Fotografías v8 con documentos, variables congeladas, sección y orientación.
  Word/PDF y comparación de revisiones usan los documentos. El lector de fotografías
  anteriores queda aislado para conservar su evidencia; no participa en crear campos.
- Los borradores históricos conservados guardan documentos en sus definiciones y
  valores anteriores; sus registros y evidencia mantienen sus identificadores.
- `composer verify` ejecutado: continúa bloqueado por `SQL_REMOTA_I-168.sql`.
  Larastan conserva 11 errores previos en módulos no modificados por I-210; se retiraron
  las clases donde estaban los restantes, sin corregir errores ajenos.
  Frontend anterior: `npm test`, 360 satisfactorias y 1 omitida.
- Cierre local de etapa 2: suite PHP completa, 374 pruebas, 373 satisfactorias y
  el mismo fallo previo de métricas; 4669 aserciones. Las pruebas de migración,
  permisos, fotografías, comparación y documentos pasan. Pint aplicado; Larastan
  sin diagnósticos nuevos. Las pruebas de tipos y rutas retirados se sustituyeron
  por cobertura de responsable/documento y evidencia histórica. No se migró producción.

### Etapa 3 — Frontend

- Constructor, ficha de Coordinación y editor docente comparten el editor documental:
  párrafos con formato, listas, tablas, filas/columnas libres y celdas combinadas.
  Administración inserta variables del catálogo. El responsable se elige o cambia
  sin convertir ni borrar el contenido del campo. Se retiraron registro y componentes
  de tipos, condiciones, tabla automática y formulario especial de discapacidad.
- Título y logos fijos; secciones con orientación y campos planos. El docente solo
  edita sus documentos, incluida bibliografía, formación y discapacidad. Coordinación
  e Institucional son de lectura. Se conservan autoguardado, conflictos, pausas,
  copia entre paralelos, revisión de biblioteca y lectura de fotografías.
- Commit frontend `a3e0cf9`. Vitest: 341 satisfactorias y 1 omitida; TypeScript,
  ESLint y compilación satisfactorios. Las pruebas retiradas corresponden a los
  tipos eliminados; se añadió cobertura del editor compartido y totales manuales.
- `composer verify` ejecutado antes del commit: mismo bloqueo de SQL. El chequeo
  estático del contrato coincide para las rutas de plantilla; queda bloqueado por
  dos consumidores previos de `spreadsheet-import.ts` con rutas dinámicas que el
  comprobador no interpreta. No se modifica ese módulo ajeno a I-210.
- Una ejecución intermedia mostró un fallo intermitente en la prueba preexistente
  de firma PDF (longitud de mensaje cifrado); la suite completa posterior pasó sin
  modificar ese código. Aceptación visual institucional y despliegue pendientes.

### Etapa 4 — Plantilla oficial y cierre

- `config/syllabus_template.php` contiene las doce secciones y 21 campos conforme al
  reparto confirmado. Identificación usa una tabla documental con variables y celdas
  combinadas; planificación horizontal trae los encabezados del PDF, datos de unidad,
  16 semanas y total vacío. No hay cálculos, validación de horas ni filas predeterminadas
  como restricción de edición. Bibliografías conservan el orden de columnas del PDF.
- Metodología conserva sus cinco apartados; evaluación y ética conservan el contenido
  institucional de referencia. Relación con el perfil de egreso usa una tabla documental
  de partida. Revisión/aprobación queda sin numeración, como en el PDF y el plan.
- Se ajustaron las ayudas de puesta en marcha y la documentación de pantallas,
  identificación y arquitectura frontend para retirar referencias a tipos, tablas
  especiales, ajuste de ficha y copia automática de formación del perfil personal.
  Los apartados de trazabilidad anteriores quedan identificados como evidencia histórica.
- Pruebas específicas de I-210: 94 satisfactorias, 2025 aserciones.
  Suite PHP completa: 376 pruebas, 375 satisfactorias, 4754 aserciones; solo persiste
  el fallo previo de métricas. La prueba nueva de Word v8 verifica tablas documentales,
  variables, orientación horizontal y anclas de las tres firmas. PDF se comprueba con
  el render local de respaldo textual; no se comprobó visualmente Word/PDF ni la
  conversión real con LibreOffice/Gotenberg.
- `composer verify` se ejecuta antes del commit y conserva el bloqueo del SQL.
  Larastan mantiene 11 errores previos, ninguno nuevo de I-210. Frontend: 341 pruebas
  satisfactorias y 1 omitida, tipos, lint y compilación satisfactorios; el comprobador
  del contrato solo mantiene los dos consumidores dinámicos previos de importación.
  OpenAPI regenerado, sin cambios nuevos de contrato en esta etapa.

### Elecciones técnicas no detalladas en el plan

- Se conserva la tercera columna/ancla de firma de Biblioteca ya vigente por I-165.
  El PDF de referencia muestra solo Docente y Coordinación; no se retira la firma
  confirmada de Biblioteca ni se crean nuevos campos o permisos.
- En una instalación sin plantilla previa, el provisionador/seeder instala el mismo
  formato configurado. La migración sustituye el formato cuando ya existe una plantilla.
- El lector aislado de fotografías anteriores sigue reproduciendo evidencia histórica;
  no conserva tipos ni compatibilidad en edición, validación o creación de campos.

### Pendiente de despliegue y aceptación

No se hizo push, no se modificó producción y no se aplicó la migración remota.
El despliegue de backend y frontend debe coordinarse. Siguen pendientes la resolución
externa de las puertas preexistentes y la aceptación visual institucional de PV-07/PV-19.
La excepción de conservación de esta carga se limita a este plan y ADR-0010;
`domain-model.md` y `syllabus-lifecycle.md` quedan idénticos al inicio de I-210.

### I-211 — Plantilla siempre presente (2026-10-09)

Sin modal de creación: la SPA crea la plantilla vacía al entrar si no existe. **Nueva sección**
solo pide nombre y orientación; se retiran los diseños oficiales del modal y de la API
(`design`, `sectionDesigns`). **Restablecer a plantilla oficial** (`POST admin/template/{id}/format`)
ahora funciona también con una plantilla con contenido: retira las secciones y campos actuales
y carga el formato oficial; con un proceso abierto sigue bloqueado y los cambios durante una pausa
rehacen los sílabos al reanudar (I-188). Título y logos siguen fijos (decisión del responsable).

Los dos controles de la hoja son botones de icono con tooltip: **Restablecer a plantilla oficial**
y **Borrar la plantilla** (`DELETE admin/template/{id}/structure`, auditado como `plantilla.vaciada`),
que deja la hoja en blanco y respeta el bloqueo por proceso abierto.

### I-212 — Constructor en la hoja (2026-10-09)

Se rehace el constructor sin cambiar la base de datos ni la API: edición en línea de secciones y
campos (nombre, quién lo llena, contenido) con autoguardado por campo, menú «/» de datos del
sistema, alta inmediata de secciones y campos sin modales y barra de formato contextual. Se
retiran `TemplateSectionDialog`, `TemplateFieldDialog` y `TemplateFieldForm`. Pendiente
(requiere decisión de producto y cambios de backend): variables de Docente y Carrera dentro del
contenido, con valores guardados en el JSON del valor del campo.

### I-213 — Tipos de campo para el admin (2026-10-09)

El menú de cada campo del constructor muestra Docente, Carrera (antes Coordinación; mismo valor `coordinacion`), Fijo y Plantilla. **Fijo** es el
antiguo «Institucional» (misma columna `quien_llena = institucional`). **Plantilla** inserta una de tres
plantillas listas (Identificación, Planificación, Firmas) que el backend entrega en `presets` desde
`config/syllabus_template.php`; no hay cambio de base de datos. Se oculta la inserción manual de variables
en el constructor. El docente y Coordinación escriben con el mismo editor y su barra de formato, y ven la
ayuda del campo sobre el editor.

### I-214 — Plantilla como JSON (2026-10-09)

Exportar e importar la plantilla como un único JSON (`GET/PUT admin/template/{id}/json`), con un editor
JSON en el admin. Formato y reglas en `docs/architecture/template-json.md`. Importar reemplaza toda la
estructura con las mismas reglas de bloqueo y reinicio que editar a mano. Se corrigió además un fallo
de datos: el middleware `TrimStrings` recortaba los espacios de los textos del contenido (por ejemplo
«Hola » antes de una palabra en negrita quedaba pegado); ahora `content` y `value` no se recortan.

### I-215 — Un solo archivo para la plantilla oficial (2026-10-09)

La plantilla oficial pasa de `config/syllabus_template.php` + `FixedContent.php` a un único JSON,
`resources/templates/silabo-oficial.json`, en el mismo formato que la importación. `OfficialTemplate` lo lee;
se retira `SectionDesigns`. Los dos archivos PHP anteriores quedan solo porque migraciones antiguas los usan.

### I-216 — Contenido en HTML dentro del JSON (2026-10-09)

El `content` de un campo se escribe y se exporta como lista de líneas HTML (también acepta una cadena HTML o el
documento Tiptap). `DocumentHtml` convierte en ambos sentidos; los 21 campos oficiales dan ida y vuelta idénticos.
El archivo oficial `resources/templates/silabo-oficial.json` pasó de 9.551 a 403 líneas (358 KB a 23 KB). La base
de datos sigue guardando el documento Tiptap.

### I-217 — Modo vista y guardado con botón (2026-10-10)

La plantilla se abre en modo vista. **Editar** crea un borrador local de toda la estructura (alta, baja,
orden, nombres, orientación y contenido); **Guardar** lo envía de una vez al endpoint de importación y
**Cancelar** lo descarta. Se retira el autoguardado por campo y el cliente `template-structure.ts`
(los endpoints de estructura siguen en el backend). El editor bloquea arrastrar y soltar.

### I-218 — Campos que son plantilla, bloqueados (2026-10-10)

Un campo es plantilla cuando su clave es la de una plantilla lista (`asignatura`, `unidades`; el payload las
entrega como `field_key`). En el constructor su nombre y contenido no se editan, no tiene responsable ni ayuda
y solo se puede mover o eliminar; aplicar la misma plantilla dos veces se rechaza. Además, el nombre de un
campo viaja junto con su contenido al repartir la hoja en páginas y los textos largos se parten por párrafo,
verificado en un navegador real con un barrido de posiciones.

