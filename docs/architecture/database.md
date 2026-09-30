# PostgreSQL y persistencia

## Convenciones

- **idioma (I-28)**: todos los identificadores del esquema (tablas, columnas, funciones,
  triggers) y los valores de estado almacenados van **en español**, con grafía sin tilde
  (`silabos`, `numero_revision`, `decision`). Las siglas técnicas se conservan (`id`,
  `uuid`, `mime`, `url`, `ip`, `sha256`, `docx`, `pdf`) igual que los nombres propios de
  formato (`markdown`, `es-EC`). Única excepción: las columnas internas de las tablas
  que los drivers de Laravel escriben con nombres fijos (`sesiones`,
  `trabajos_fallidos`, `restablecimientos_contrasena`, `migraciones`) — registrada como
  deuda técnica. Las clases y rutas de código siguen en inglés (precedente I-14).
  `SpanishSchemaTest` y `SpanishModelColumnsTest` hacen cumplir la regla.
- fechas: las tablas de dominio no conservan los metadatos genéricos `creado_en`,
  `actualizado_en` ni `registrado_en` (I-52). Solo persisten fechas que participan en el
  comportamiento o explican un hecho del dominio, con nombre explícito: por ejemplo
  `asignado_en`, `guardado_en`, `observado_en`, `notificado_en`, `encolado_en` y
  `almacenado_en`. La historia técnica se consulta en `eventos_auditoria`; crear en el
  futuro una auditoría automática mediante triggers requiere una decisión y migración
  separadas.
- tablas/columnas: plural y `snake_case`;
- los códigos de negocio se nombran con la entidad que identifican:
  `codigo_carrera`, `codigo_asignatura` y `codigo_periodo_academico`. Facultades se
  identifican por nombre y campus; el campus no tiene código ni entidad independiente
  (I-106, I-107);
- claves primarias internas: UUID generados por aplicación;
- claves foráneas e índices explícitos;
- tiempo: `timestamptz` en UTC; la conexión fija `DB_TIMEZONE=UTC` y la conversión a
  `America/Guayaquil` ocurre solo al presentar;
- estados de cardinalidad baja: texto legible + `CHECK` o enum PHP coherente;
- integridad: huellas SHA-256 como `char(64)` cuando aplique;
- JSON solo para datos variables que no requieren relaciones/consultas invariantes;
- campos de plantilla no cambian el esquema físico.

## Catálogo por módulo

### Identidad

`usuarios`, `asignaciones_rol`. Los roles son los cuatro valores fijos del enum de
dominio (`administrador`, `coordinador`, `docente` y, desde I-165, `bibliotecario`, sin
carrera como el administrador), almacenados en
`asignaciones_rol.rol`; no existe un catálogo administrable `roles`. Las columnas de `usuarios` heredadas del
starter quedaron en español en I-28 y sus atributos de dominio son explícitos desde
I-78 (`nombre_usuario`, `correo_electronico`, `contrasena`, `usuario_activo`,
`codigo_recordarme`, `secreto_dos_factores`…); el modelo conserva `nombre` y `activo`
como contrato de aplicación. I-50 deja `usuario_activo` como estado actual y registra toda activación/desactivación en
`eventos_auditoria`, sin duplicar fecha de baja o cédula de la integración retirada.
I-51 retira la vigencia laboral de la cuenta: su disponibilidad se expresa con `usuario_activo`
y sus asignaciones operativas. El modelo `User` declara los puentes que Fortify y el
guard exigen. I-29 hace las asignaciones de rol manuales: `asignaciones_rol.asignacion_rol_activa`
determina su efectividad, `asignado_en` (obligatoria desde I-168) ordena el historial y no existen fechas
programadas de inicio o fin.

### Académico

`facultades`, `carreras`,
`periodos_academicos`, `asignaturas`, `requisitos_asignatura`,
`programaciones_asignatura`, `paralelos`, `asignaciones_paralelo`.

`asignaciones_rol` es única relación RBAC: une usuario, rol fijo y alcance de carrera. Una
fila activa con rol `coordinador` expresa directamente quién coordina esa carrera; no
existe segunda tabla de coordinación. Un trigger PostgreSQL garantiza como máximo una
coordinación ejercible por carrera (rol activo y cuenta activa); la aplicación muestra
el error de negocio antes de llegar a la restricción.

`asignaciones_paralelo` vincula una asignación de rol `docente` con un paralelo, conserva
`asignado_en`; no tiene intervalo de vigencia laboral. Su identidad activa única es
`paralelo_id`: un paralelo solo puede tener un docente activo, sin importar la persona.
Las filas inactivas se conservan como historial y pueden coexistir con una nueva
asignación posterior; un relevo finaliza la relación anterior y crea otra.

`carreras.facultad_id` implementa la relación uno-a-muchos Facultad → Carreras con clave
foránea y borrado restringido. `facultades.campus_facultad` es un valor obligatorio
limitado por `CHECK` a las cinco opciones de `CampusLocation`. No existe tabla, código,
clave foránea ni CRUD de campus. `periodos_academicos` conserva identidad propia; el
período es institucional, se identifica y presenta por su código único generado por el
servidor a partir del rango de meses y el año. `cantidad_semanas_lectivas` se calcula contando
las semanas de lunes a domingo que toca el rango, incluidas las parciales, y queda entre
1 y 52. El período no depende de una carrera. Su estado se deriva exclusivamente de sus
fechas.
`programaciones_asignatura` relaciona cada período con una asignatura mediante claves
foráneas. La modalidad no es tabla sino
columna de texto con valores fijos (`carreras.modalidad_carrera` base,
`asignaturas.modalidad_asignatura` opcional; migraciones `000034` y `000052`, I-37/I-62).
Desde I-168 un `CHECK` limita ambas a `presencial`, `semipresencial`, `en_linea`,
`a_distancia` e `hibrida` (`StudyModality`). Desde I-164 (migración `000159`)
la programación **no guarda** campus ni modalidad: el modelo `ScheduledSubject` los deriva
de la materia, su carrera y su facultad, y el sílabo toma esa lectura como fotografía en
su contexto institucional al crearse o regenerarse. Antes existían `campus_programacion`
y `modalidad_programacion_asignatura` como copias que había que refrescar a mano. La
jerarquía que presenta ADM-04 es una proyección de lectura y no una desnormalización de
la persistencia.

`paralelos` no tiene estado propio: antes de tener historia se elimina y, una vez que
participa en un sílabo, se conserva. Su operatividad se deriva del período y de su
programación, sin duplicar un booleano individual. Todo paralelo tiene código único por
materia programada y `jornada_paralelo` obligatoria (`matutina`, `vespertina` o
`nocturna`); la migración `000112` rellenó los nulos con `matutina` y dejó la columna
NOT NULL (I-141).

La estructura curricular no es una entidad separada: `carreras.cantidad_ciclos_malla`
define la cantidad de ciclos de la única estructura de esa carrera. El código obligatorio
de identidad es `carreras.codigo_carrera`; no existe código de malla.
`asignaturas.carrera_id` las relaciona directamente. Los
atributos académicos de `asignaturas` son fijos y tipados: ninguna carrera agrega
columnas o redefine esos atributos mediante valores EAV. I-145 admite contenido
documental configurable en conceptos y valores curriculares relacionados, descritos
más abajo. ACD, APE, AA, créditos y total son un
conjunto fijo; `total_horas_asignatura` es una columna generada `STORED` igual a
`round(horas_ac + horas_pae + horas_aa)` (I-132) y no se escribe desde la aplicación.
Las columnas `horas_proyecto`, `horas_ap` y `horas_paec`, heredadas del sistema anterior
(I-11) y sin dato ni pantalla, se retiraron en I-162. La migración `000100` trasladó a `horas_pae` el saldo de las filas cuyo total superaba la suma de
componentes (prácticas preprofesionales y de servicio comunitario), sin perder horas.
`asignaturas.ciclo_asignatura` y `orden_asignatura_en_ciclo` determinan la posición reproducible del lienzo.
Las coordenadas de pantalla no se persisten. `requisitos_asignatura.tipo_requisito_asignatura`
conserva la semántica explícita de cada flecha: un `CHECK` la limita a `prerrequisito` o
`correquisito` y, desde I-168, un par de materias tiene un solo tipo (`requisitos_asignatura_par_unico`).

`silabos.contexto_academico_silabo` (alias `contexto_academico`) guarda la lectura JSON de la
estructura curricular, la asignatura, su programación y los datos curriculares al crear el
expediente. No es evidencia inmutable: `InProgressWork` la vuelve a sincronizar cuando una
corrección autorizada con el ámbito pausado cambia la carrera, la materia o su programación,
y `ResetSyllabus` la reconstruye. La evidencia inmutable es
`revisiones_silabo.fotografia_revision_silabo`. No sustituye las relaciones transaccionales
ni permite reconstruir autorizaciones.

I-144 guardaba resultados de aprendizaje en `bases_resultados_asignatura` y
`resultados_aprendizaje_asignatura`; I-147 retiró su escritura y I-168 (migración `000166`)
eliminó ambas tablas, que estaban vacías. Los resultados se confirman en
`valores_curriculares` (concepto `resultados_aprendizaje`). Los sílabos antiguos que
fotografiaron `subject.learning_outcomes` se siguen leyendo desde esa copia. Si el formato
usa resultados y su copia está vacía, la validación impide enviar.

### Datos curriculares documentales (I-145)

La migración `2026_09_27_000145_create_curricular_imports` incorpora:

- `conceptos_curriculares`: clave global única, etiqueta, tipo `texto|lista|tabla` y
  columnas declaradas para tablas. Un trigger impide cambiar clave, tipo o columnas
  después de crear el concepto. Desde I-160 (`2026_09_29_000158`) no existe ámbito:
  todo dato del Proyecto de carrera es de una asignatura; la columna `ambito_concepto`
  se eliminó.
- `valores_curriculares`: FK de concepto/carrera/asignatura (obligatoria desde I-160),
  contenido JSONB, evidencia y persona/fecha de confirmación. El valor es único por
  concepto y asignatura. La FK compuesta `(asignatura_id,carrera_id)` impide mezclar
  carreras; un trigger comprueba el tipo JSON general. La aplicación valida cada texto,
  elemento de lista y celda, incluidas las claves exactas de columnas. La migración
  I-160 copió cualquier valor «de toda la carrera» a las asignaturas de esa carrera que
  no tenían el suyo y borró los valores sin asignatura.
- `importaciones_curriculares`: carrera, solicitante, clave de idempotencia,
  estado `pendiente|en_ejecucion|lista|aplicada|fallida`, documentos con huellas,
  instrucciones opcionales, propuesta, selección aplicada y fechas. La combinación
  carrera/clave de idempotencia es única. Sus filas representan operaciones de
  importación, sin reintroducir versiones de malla ni de fuente.

La propuesta no es contenido vigente. `ApplyCurricularImport` bloquea carrera,
importación, fuentes y destinos, coteja la huella consultada y verifica de nuevo los
documentos activos. Esa huella incluye los valores actuales de la carrera para
detectar otra importación aplicada entre consulta y confirmación. Solo se aceptan
IDs de filas existentes, una fila por concepto/destino y materias de la carrera.
La selección aplicada conserva IDs, asignaturas resueltas y aceptación de discrepancia.
La auditoría general contiene conteos y huellas; el contenido vive en las tablas
autorizadas, no en logs.

`contexto_academico.curricular` fotografía definiciones, valores y evidencia de la
carrera/asignatura al generar cada sílabo. La resolución de `curricular_{clave}` y de
opciones docentes usa esa copia. Los datos ausentes bloquean el envío cuando el
formato los requiere, sin consultar la base viva para completar una revisión histórica.
Las variables de resultados, fuente y ubicación salen del concepto
`resultados_aprendizaje` de tipo lista; solo los sílabos antiguos conservan la copia I-144.
Corregir valores durante una pausa usa `InProgressWork` con el conjunto de materias
afectadas; un valor de carrera afecta a toda esa carrera. Los expedientes con
aprobación conservada quedan fuera del reemplazo. Borrar una materia sin programación
ni sílabos elimina sus valores actuales y conserva el registro de importación.

### Plantilla y documentos internos de ingesta

`plantilla_silabo`, `secciones_plantilla`, `campos_plantilla`, `definiciones_campo` y
`fuentes_academicas` (nombre físico histórico del documento temporal de importación
I-145/I-152). I-168 eliminó `documentos_ia`, su trigger y la extensión `vector`: la
búsqueda vectorial retirada en I-146 no tenía datos.

`plantilla_silabo` no referencia una carrera y solo admite una fila. El índice único
`plantilla_silabo_unica` aplica esa cardinalidad directamente, sin un booleano
`es_institucional` que siempre tendría el mismo valor. La plantilla se edita en el sitio
y cada revisión enviada conserva su propia fotografía inmutable durante el flujo normal
(I-32, I-52). I-118 habilita mediante una variable local de transacción el borrado
excepcional del agregado elegible completo cuando una base institucional se corrige con
el ámbito correspondiente pausado; fuera de esa transacción los triggers conservan la
inmutabilidad. La aclaración I-144 excluye expedientes con aprobación conservada, aunque
hayan sido reabiertos, y procesos cerrados.
Desde I-164 (migración `000161`) las secciones y campos de la plantilla son filas de
`secciones_plantilla` y `campos_plantilla` (antes, JSON en `documento_plantilla`, I-111);
`definiciones_campo.campo_plantilla_id` es una llave foránea real. I-166 (migración
`000165`) renombró `bloques_plantilla` a `campos_plantilla` y dio nombres explícitos a las
columnas de ambas tablas. Solo el diseño de cada hoja
(`campos_plantilla.configuracion_campo_plantilla.document`) sigue en JSON, porque es un
documento.
El modelo `SyllabusTemplate` arma y vuelca ese árbol (`sections()`, `blocks()`,
`documento`), así que la aplicación y la API no cambiaron. `secciones_plantilla`,
`campos_plantilla` y `definiciones_campo` guardan `plantilla_id` para sus restricciones
únicas por plantilla; desde I-168 las FK compuestas
`campos_plantilla_seccion_misma_plantilla_foreign` y
`definiciones_campo_campo_misma_plantilla_foreign` impiden que un hijo apunte a otra
plantilla que la de su padre. `silabos`, `convocatorias_universidad`,
`artefactos_exportacion` y el historial de IA no la guardan (I-132, I-135) y la aplicación la
resuelve con `SyllabusTemplate::institutional()`. La exportación renderiza desde
`revisiones_silabo.fotografia_revision_silabo`, no desde la plantilla vigente.
Cada campo guarda su tipo de contenido una sola vez en `configuracion.content_type`
(`text`, `table`, listas, `institutional` o `fixed` = contenido fijo sin campos); el tipo de
almacenamiento se deriva de él (I-138).

Desde I-168 (migración `000170`) cada dato del docente se describe **una sola vez**, en
`definiciones_campo`: nombre, tipo, opciones y condición. El JSON de la hoja solo guarda,
en cada nodo `field`, su `key` y lo que es de diseño (`choice` para una marca X y
`listStyle`); los orígenes de opciones viven en `configuracion.options_sources`.
`TemplateFieldAttributes` completa los nodos al leer (`SyllabusTemplate::loadSections`) y
los recorta al guardar (`syncDocument`), así que el editor y las fotografías siguen
recibiendo el nodo completo. `configuracion.detached_fields` conserva, por clave, si un
campo retirado de la hoja era editable, para restaurarlo si vuelve.

`definiciones_campo.mostrar_si_definicion_campo` (alias `mostrar_si`) guarda
`{key, value}` apuntando a otro campo de la misma plantilla por su clave. Desde I-168 la
base lo protege como una FK: el trigger diferido `validar_condicion_definicion` rechaza
una clave inexistente y `propagar_clave_condicion` anula la condición si el campo
referido se borra o la actualiza si cambia de clave. Un `CHECK`
(`definiciones_campo_heredado_check`) exige que un campo heredado tenga origen y no sea
editable por el docente. Las columnas de `definiciones_campo` y otras renombradas en I-168
usan su nombre completo (`heredado_definicion_campo`, `origen_maestro_definicion_campo`,
`editable_docente_definicion_campo`, `mostrar_si_definicion_campo`,
`convocatorias_carreras.convocatoria_universidad_id`, `silabos.convocatoria_carrera_id`,
`asignaciones_paralelo.asignacion_paralelo_activa`,
`ejecuciones_validacion.cantidad_errores_validacion`); los modelos conservan el nombre corto
como alias. I-168 retiró `ia_habilitada` y `marcador_documento` (se escribían y nadie los
leía), `valores_campo.heredado` y `origen_valor_campo` (copias de la definición) y
`plantilla_silabo.revision_configuracion` con sus cuatro triggers `tocar_revision_*`.
No hay reglas por campo: `reglas_definicion_campo` se eliminó (migración `000111`). Todo
campo del docente es obligatorio al enviar; el estado `borrador` puede quedar incompleto.

`fuentes_academicas` conserva un documento temporal de importación por fila: nombre único
por carrera y `documento_fuente_academica` JSON interno. Desde I-146 se escribe mediante
la ingesta I-145 y no mediante un editor o CRUD público. I-168 retiró
`contenido_fuente_academica` (la proyección textual del índice vectorial),
`version_documento_fuente` y `descripcion_fuente_academica`; el texto se deriva del
documento con `AcademicSourceDocument::text()`. La migración `000054` retira
`notas_internas`; las tablas `versiones_fuente`, `fragmentos_fuente` y `conflictos_fuente`
se retiraron en la migración `000020`.
I-95/I-96 incorporan el PDF privado y producen el documento JSON con OpenParser sobre
`paddleocr-vl-1.6`. La
migración `000089` agrega el documento versionado, procedencia por fragmento y retira
`extracciones_fuente_academica`; el estado remoto vive en `ejecuciones_trabajo`. Las
tablas conservan `rowspan`/`colspan` y las imágenes raster se guardan como objetos
privados `academic_source_image`; Base64 y URLs firmadas no se persisten.

### Convocatorias y sílabos

`convocatorias_universidad`, `convocatorias_carreras`,
`silabos`, vista `docentes_vigentes_silabo` (I-130),
`revisiones_silabo`, `valores_campo`. I-156 retira
`filas_repetibles`: el Texto libre del docente vive en `valores_campo` como documento JSON.

I-146 elimina las fuentes como requisito para convocar. No se seleccionan ni se consultan
para revisión por IA. La migración histórica `000113` ya había eliminado
`fuentes_convocatoria`; I-146 no requiere borrar información histórica.

`convocatorias_universidad` (I-31, I-41) es el calendario institucional: período académico,
inicio (`inicia_en`), entrega (`entrega_en`) y estado. Las convocatorias de carrera leen
esas fechas; `fechas_limite_convocatoria`, que las copiaba por carrera, se retiró en la
migración `000100` (I-132) y la prórroga solo mueve `entrega_en`. Los actores y momentos de sus
transiciones viven en `eventos_auditoria`. Un índice parcial único sobre
`estado_convocatoria_universidad IN ('abierto', 'pausado')` garantiza un solo proceso en curso.
`convocatorias_carreras.convocatoria_universidad_id` (antes `proceso_id`, alias en el modelo)
es obligatorio y permite heredar el período y las fechas sin copiarlos en la tabla de
carrera. Su identidad única es `carrera_id + convocatoria_universidad_id`; solo conserva el estado actual, mientras los actores y
momentos de transición viven en auditoría. Un trigger PostgreSQL bloquea cambiar
el período de una convocatoria universitaria que ya tiene convocatorias de carrera. La
migración `000027` creó un proceso por cada convocatoria existente con su propia
plantilla y fechas. El estado `pausada` de la convocatoria y `pausado` del proceso
detienen el trabajo docente sin borrar nada.
Desde I-164 (migración `000160`) `silabos` ya no guarda `asignatura_id`: la materia se
lee del paralelo (paralelo → programación → materia; `Syllabus::subject` es derivado y
`Syllabus::forSubjects()` filtra por esa vía). Cada sílabo cubre exactamente un paralelo: `silabos.paralelo_id` es obligatorio y único
junto con `convocatoria_carrera_id` (antes `convocatoria_id`, alias en el modelo). La migración `000099` (I-131) retiró `alcances_silabo`
(la materia programada se deriva de `paralelos.programacion_asignatura_id`) y
`silabos.carrera_id` (la carrera es la de `convocatorias_carreras`); la vista
`docentes_vigentes_silabo` se recreó sobre `silabos.paralelo_id`.
Una corrección confirmada durante esa pausa usa `InProgressWork`: guarda el cambio,
elimina las dependencias de revisión, IA y exportación de los expedientes elegibles en
curso y regenera desde la programación vigente antes del commit. Incluye los enviados
sin aprobación; preserva los aprobados y la evidencia aprobada tras una reapertura.
Si la regeneración falla, PostgreSQL revierte tanto la corrección como el borrado
(I-118, aclarada en I-144). El cambio de resultados de aprendizaje añade el filtro por
asignatura para conservar las otras materias.

### Revisión, validación e IA

`revisiones_silabo`, `observaciones_revision`, `solicitudes_correccion`, `aprobaciones`,
`reaperturas`, `envios_biblioteca` (I-165), `ejecuciones_validacion`,
`resultados_validacion` y la memoria de IA `historial_ia` (I-135). I-120 eliminó `respuestas_observacion` y el estado `respondida`:
el docente corrige y reenvía, y una observación está abierta o verificada.
Desde I-131, `aprobaciones`, `reaperturas`, `solicitudes_correccion` y
`artefactos_exportacion` no guardan `silabo_id`: el sílabo se obtiene de la revisión
(`revision_silabo_id` o `revision_aprobada_id`). La unicidad por sílabo de las claves de
idempotencia de aprobaciones y reaperturas la verifica `validar_relaciones_flujo`.
Desde I-136 no existe `transiciones_silabo`: los cambios de estado del sílabo se
registran solo en `eventos_auditoria`, con `from_state`/`to_state` en los metadatos.
Desde I-134, una solicitud de corrección envía todas las observaciones abiertas de su
revisión (se exige al menos una); la migración `000102` eliminó
`solicitud_correccion_observaciones` y la rama correspondiente de
`validar_relaciones_flujo`; I-168 eliminó el trigger `validar_solicitud_correccion`, que
quedó sin nada que validar. Las observaciones incluidas son las de
`observaciones_revision` con el mismo `revision_silabo_id`. I-116 eliminó
`preferencias_ia_carrera`; I-168 retiró también la marca de IA por campo, que no se usaba.

I-135 reemplazó las once tablas de IA por `historial_ia`, que guarda un mensaje JSONB
por fila (`id`, `sesion_id`, `mensaje_ia`, `enviado_en`). Desde I-168 solo admite la sesión
del chat `silabo:{id}:usuario:{usuario}`, los tipos `mensaje` (rol `usuario` o `asistente`,
evidencia y citas) y `ocultamiento` (limpia el panel sin borrar) y los estados
`pendiente`, `en_ejecucion`, `completado`, `fallido` y `registrada`. Se retiraron los tipos,
sesiones e índices del revisor (análisis por campo, revisión integral y decisiones), que
no tenían datos.

`mensaje_ia.entrada` es la entrada fijada: el trigger `proteger_historial_ia` impide
cambiarla y congela todo mensaje que salió de `pendiente`/`en_ejecucion`; solo el
reinicio institucional confirmado (I-118) puede borrar. El índice único parcial
`historial_ia_mensaje_idempotencia_unica` evita repetir una pregunta con la misma clave.
El progreso técnico vive en `ejecuciones_trabajo` (`ia.chat`), cuyo `recurso_id` es el id
del mensaje. Al eliminar un sílabo, el trigger `eliminar_historial_ia_de_silabo` borra sus
sesiones. La evidencia sigue siendo una fotografía (etiqueta, extracto y huella)
inmutable aunque cambien luego el documento o el borrador.

### Operación e integración

`objetos_almacenados`, `artefactos_exportacion`, `notificaciones_internas`,
`eventos_auditoria` y `ejecuciones_trabajo`. La entrada del trabajo se guarda en la
misma transacción que el cambio funcional; después del commit se despacha el job.
Los archivos persistentes se describen mediante `objetos_almacenados`: por ejemplo,
`facultades.logo_objeto_id` referencia el objeto privado de su logo. La tabla de
dominio nunca almacena una ruta del disco como dato de negocio.
Los momentos funcionales se llaman respectivamente `almacenado_en`, `notificado_en`,
`ocurrido_en` y `encolado_en`; no se duplican con timestamps técnicos de Eloquent.
`objetos_almacenados.estado_objeto_almacenado` solo admite `activo` y
`eventos_auditoria.resultado_evento_auditoria` solo `exito` o `fallido` (I-168 quitó
`en_cuarentena` y `denegado`, que nunca se escribían). `firmas_documento` es evidencia
append-only: el trigger `proteger_firma_documento` usa `proteger_registro_append_only`
como las demás tablas de evidencia. `artefactos_exportacion` ya no guarda
`asignacion_rol_id`: el rol queda en `eventos_auditoria`.
Las tablas de importación institucional (`ejecuciones_importacion`, `items_importacion`,
`conflictos_importacion`, `alias_institucionales`) se retiraron el 2026-08-27 junto con
el módulo que las usaba.

### Framework

Colas y caché operan sobre Redis, así que `jobs`, `job_batches`, `cache` y
`cache_locks` se eliminaron en I-28. Sobreviven, renombradas por configuración
soportada: `sesiones` (driver de sesión `database`), `trabajos_fallidos` (registro
forense de trabajos agotados), `restablecimientos_contrasena` (broker de recuperación)
y `migraciones` (control del migrador; su renombrado se hace con
`php artisan db:rename-migrations-table` antes de `migrate`, nunca dentro de una
migración). Sus columnas internas conservan los nombres que los drivers exigen.

Los nombres definitivos se implementan desde el modelo físico v0.1 y se modifican solo
mediante migración/ADR.

## Inmutabilidad

Las tablas de revisión, aprobación, versión publicada, evidencia y auditoría no admiten
edición funcional. Las correcciones agregan filas. Si es necesario corregir metadatos
administrativos, se registra el cambio y se preserva el valor anterior. ADM-04 implementa
esta corrección mediante actualización transaccional del catálogo y un evento append-only
con campos modificados y valores anterior/nuevo; no requiere desnormalizar ni duplicar la
entidad. La estructura curricular de la carrera no es una versión publicada: permanece editable y su historia se
protege mediante el contexto académico fijado en cada sílabo.

## Borrado

- `RESTRICT` para referencias históricas.
- `CASCADE` únicamente entre padre e hijos que no tienen sentido independiente y aún no
  constituyen evidencia publicada.
- las materias sin dependencias se eliminan; con programaciones o sílabos se protegen y
  se explica el motivo.
- un catálogo sin dependencias se elimina; si tiene historia, las claves foráneas lo
  protegen y la aplicación muestra el motivo. Las cuentas con historia se desactivan,
  porque su identidad participa en auditoría y revisiones.
- migraciones destructivas requieren copia, verificación, rollback ensayado y aprobación.

## Índices y restricciones

Desde I-168 (migraciones `000168` y `000169`) toda restricción NOT NULL se llama
`{tabla}_{columna}_not_null`, toda llave primaria `{tabla}_pkey` y toda llave foránea de una
columna `{tabla}_{columna}_foreign`; los índices y `CHECK` heredados de nombres anteriores
(`campanias_*`, `procesos_silabos_*`, `plantillas_silabo_*`…) se renombraron con el prefijo
de su tabla. Se retiraron índices sobre un solo booleano y los duplicados.

Toda llave foránea de una sola columna encabeza algún índice (I-133, migración
`000101`): PostgreSQL no los crea solo y, sin ellos, filtrar por la relación o borrar el
padre recorre la tabla hija. Una llave foránea nueva debe llegar con su índice.

Como mínimo, prueba/define:

- unicidad de identificadores institucionales no nulos;
- una sola coordinación activa por carrera mediante índice parcial único;
- identidad del sílabo canónico;
- número de revisión único por sílabo;
- nombre de fuente único por carrera;
- claves de idempotencia únicas por operación;
- filtros frecuentes por convocatoria, estado, asignación, plazo y fecha;
- búsquedas de auditoría por recurso/actor/tiempo;
- una estructura curricular única como atributos obligatorios de cada carrera;
- código de asignatura único dentro de su carrera y datos académicos tipados por asignatura;
- colas y trabajos por estado, idempotencia y próximo intento.

Usa índices parciales o constraints de exclusión PostgreSQL cuando expresen mejor la
regla. Acompáñalos con pruebas en PostgreSQL real.

`DatabaseBootstrapTest` comprueba la zona efectiva de la sesión PostgreSQL y ejecuta el
seeder dos veces. Las asignaciones activas se reutilizan para conservar la idempotencia.

### Invariantes de IA

El chat usa contexto académico/curricular y tablas de unidades/planificación, con
`context_version=curricular-units-v1`; no recupera fuentes ni aplica recomendaciones.

- una clave idempotente no crea dos preguntas dentro de la misma sesión;
- cada respuesta conserva evidencia propia y únicamente puede citar IDs de ese conjunto;
  limpiar la vista no borra el historial auditable;
- un mensaje terminal es append-only.

Las invariantes del módulo de importación institucional se retiraron con sus tablas el
2026-08-27.

## Migraciones

- Una migración hace una transformación coherente y revisable.
- No edites una migración aplicada en entornos compartidos.
- Separa backfill costoso del cambio de constraint cuando reduzca riesgo.
- Datos de catálogo controlados usan seeders idempotentes; no ocultes datos académicos
  productivos dentro de migraciones.
- Documenta forward, verificación y recuperación antes de ejecutar en producción.

## Rendimiento

- Pagina en servidor.
- Evita N+1 con cargas explícitas.
- No carga el JSON completo de un sílabo para cada fila de una cola.
- Mide con `EXPLAIN (ANALYZE, BUFFERS)` en datos representativos antes de agregar índices.
- No uses Redis para compensar una consulta incorrecta sin baseline.
