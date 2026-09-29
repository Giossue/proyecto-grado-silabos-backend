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

`usuarios`, `asignaciones_rol`. Los roles son los tres valores fijos del enum de
dominio (`administrador`, `coordinador`, `docente`), almacenados en
`asignaciones_rol.rol`; no existe un catálogo administrable `roles`. Las columnas de `usuarios` heredadas del
starter quedaron en español en I-28 y sus atributos de dominio son explícitos desde
I-78 (`nombre_usuario`, `correo_electronico`, `contrasena`, `usuario_activo`,
`codigo_recordarme`, `secreto_dos_factores`…); el modelo conserva `nombre` y `activo`
como contrato de aplicación. I-50 deja `usuario_activo` como estado actual y registra toda activación/desactivación en
`eventos_auditoria`, sin duplicar fecha de baja o cédula de la integración retirada.
I-51 retira la vigencia laboral de la cuenta: su disponibilidad se expresa con `usuario_activo`
y sus asignaciones operativas. El modelo `User` declara los puentes que Fortify y el
guard exigen. I-29 hace las asignaciones de rol manuales: `asignaciones_rol.asignacion_rol_activa`
determina su efectividad, `asignado_en` ordena el historial y no existen fechas
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
servidor a partir del rango de meses y el año. `semanas_lectivas` se calcula contando
las semanas de lunes a domingo que toca el rango, incluidas las parciales, y queda entre
1 y 52. El período no depende de una carrera. Su estado se deriva exclusivamente de sus
fechas.
`programaciones_asignatura` relaciona cada período con una asignatura mediante claves
foráneas. La modalidad no es tabla sino
columna de texto con valores fijos (`carreras.modalidad` base, `asignaturas.modalidad`
opcional; migraciones `000034` y `000052`, I-37/I-62). Desde I-164 (migración `000159`)
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
`asignaturas.ciclo` y `orden_en_ciclo` determinan la posición reproducible del lienzo.
Las coordenadas de pantalla no se persisten. `requisitos_asignatura.tipo` conserva la
semántica explícita de cada flecha.

`silabos.contexto_academico` conserva una fotografía JSON de la estructura curricular, la asignatura y su
programación al crear el expediente. Es evidencia histórica de lectura y exportación; no
sustituye las relaciones transaccionales ni permite reconstruir autorizaciones.

I-144 agrega `bases_resultados_asignatura` (una cabecera por asignatura, con documento
fuente, localizador y confirmación de Coordinación) y `resultados_aprendizaje_asignatura`
(filas con identidad estable, texto y posición única por cabecera). La cabecera referencia
asignatura y usuario confirmador con borrado restringido; sus filas tienen cascada.
Eliminar una materia sin dependencias elimina antes su cabecera. Una materia programada
conserva los bloqueos de borrado existentes. No son atributos arbitrarios de malla.
I-147 conserva estas tablas para lectura histórica y retira su escritura pública;
los resultados nuevos se confirman en `valores_curriculares` mediante importación.

La escritura histórica I-144 reemplazaba la lista en una transacción y comparaba una huella de lo consultado
para detectar concurrencia. `contexto_academico.subject.learning_outcomes` fotografía
contenido, orden y procedencia al generar cada sílabo. La exportación resuelve los datos
desde esa fotografía, sin consultas al catálogo vivo. El catálogo de variables expone
resultados como lista numerada, fuente y ubicación. Si el formato usa resultados y su
copia está vacía, la validación impide enviar; borradores históricos sin esa variable
continúan funcionando. I-144 limita su reinicio a la asignatura corregida y conserva
expedientes con aprobación, incluidas las revisiones aprobadas enlazadas tras reapertura.

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
Los aliases I-144 de resultados, fuente y ubicación priorizan el concepto genérico
`resultados_aprendizaje` de tipo lista cuando existe en la
fotografía. En su ausencia conservan la base fotografiada I-144. No se sincronizan ni
duplican filas entre ambos modelos actuales.
Corregir valores durante una pausa usa `InProgressWork` con el conjunto de materias
afectadas; un valor de carrera afecta a toda esa carrera. Los expedientes con
aprobación conservada quedan fuera del reemplazo. Borrar una materia sin programación
ni sílabos elimina sus valores actuales y conserva el registro de importación.

### Plantilla y documentos internos de ingesta

`plantilla_silabo`, `definiciones_campo` y `fuentes_academicas` (nombre físico histórico
del documento interno de ingesta I-145). I-146 no elimina tablas ni evidencia: retira
el CRUD público y desactiva el uso de `documentos_ia` para recuperación vectorial.

`plantilla_silabo` no referencia una carrera y solo admite una fila. El índice único
`plantilla_silabo_unica` aplica esa cardinalidad directamente, sin un booleano
`es_institucional` que siempre tendría el mismo valor. La plantilla se edita en el sitio
y cada revisión enviada conserva su propia fotografía inmutable durante el flujo normal
(I-32, I-52). I-118 habilita mediante una variable local de transacción el borrado
excepcional del agregado elegible completo cuando una base institucional se corrige con
el ámbito correspondiente pausado; fuera de esa transacción los triggers conservan la
inmutabilidad. La aclaración I-144 excluye expedientes con aprobación conservada, aunque
hayan sido reabiertos, y procesos cerrados.
`plantilla_silabo.documento_plantilla` guarda en JSONB versionado la composición visual
de secciones, bloques y tablas (I-111). Las definiciones de campo siguen en filas y
`bloque_plantilla_id` apunta al UUID estable del bloque embebido; triggers de PostgreSQL
impiden referencias huérfanas en ambas direcciones. `definiciones_campo.plantilla_id`
es la única referencia persistida a la plantilla: `silabos`, `convocatorias_universidad`,
`artefactos_exportacion` y el historial de IA ya no la guardan (I-132, I-135) y la aplicación la
resuelve con `SyllabusTemplate::institutional()`. La exportación renderiza desde
`revisiones_silabo.fotografia`, no desde la plantilla vigente. Cada bloque guarda su tipo una
sola vez en `configuracion.content_type` (`text`, `table`, listas, `institutional` o `fixed` =
contenido fijo sin campos); el tipo de almacenamiento se deriva de él (I-138).
No hay reglas por campo: `reglas_definicion_campo` se eliminó (migración `000111`). Todo
campo del docente es obligatorio al enviar; el estado `borrador` puede quedar incompleto.

`fuentes_academicas` conserva un documento por fila: nombre único por carrera,
descripción y `documento_fuente_academica` JSON interno. Desde I-146 se escribe mediante
la ingesta I-145 y no mediante un editor o CRUD público. `contenido_fuente_academica`
es una proyección textual para búsqueda y compatibilidad. La migración `000054` retira
`notas_internas`; las tablas
`versiones_fuente`, `fragmentos_fuente` y `conflictos_fuente` se retiraron en la
migración `000020`; no eran compatibles con el documento único editable. La migración
`000078` incorporó un índice derivado de fragmentos y la `000087` su embedding
`vector(1536)` de `text-embedding-3-small`. Desde I-135 (migración `000103`) ese índice
es `documentos_ia`, estructura histórica con texto, metadatos, embeddings e índices
HNSW/GIN. I-146 retira su indexación y recuperación activas; los registros y restricciones
existentes se conservan. No se usa como fuente de verdad ni como contexto del chat. La
evidencia histórica sigue fotografiada y no se reconstruye por esta retirada.
I-95/I-96 incorporan el PDF privado y producen el documento JSON con OpenParser sobre
`paddleocr-vl-1.6`. La
migración `000089` agrega el documento versionado, procedencia por fragmento y retira
`extracciones_fuente_academica`; el estado remoto vive en `ejecuciones_trabajo`. Las
tablas conservan `rowspan`/`colspan` y las imágenes raster se guardan como objetos
privados `academic_source_image`; Base64 y URLs firmadas no se persisten.

### Convocatorias y sílabos

`convocatorias_universidad`, `convocatorias_carreras`,
`silabos`, vista `docentes_vigentes_silabo` (I-130),
`revisiones_silabo`, `valores_campo`, `transiciones_estado`. I-156 retira
`filas_repetibles`: el Texto libre del docente vive en `valores_campo` como documento JSON.

I-146 elimina las fuentes como requisito para convocar. No se seleccionan ni se consultan
para revisión por IA. La migración histórica `000113` ya había eliminado
`fuentes_convocatoria`; I-146 no requiere borrar información histórica.

`convocatorias_universidad` (I-31, I-41) es el calendario institucional: período académico,
inicio (`inicia_en`), entrega (`entrega_en`) y estado. Las convocatorias de carrera leen
esas fechas; `fechas_limite_convocatoria`, que las copiaba por carrera, se retiró en la
migración `000100` (I-132) y la prórroga solo mueve `entrega_en`. Los actores y momentos de sus
transiciones viven en `eventos_auditoria`. Un índice parcial único sobre
`estado IN ('abierto', 'pausado')` garantiza un solo proceso en curso.
`convocatorias_carreras.proceso_id` es obligatorio y permite heredar el período y las
fechas sin copiarlos en la tabla de carrera. Su identidad única es
`carrera_id + proceso_id`; solo conserva el estado actual, mientras los actores y
momentos de transición viven en auditoría. Un trigger PostgreSQL bloquea cambiar
el período de una convocatoria universitaria que ya tiene convocatorias de carrera. La
migración `000027` creó un proceso por cada convocatoria existente con su propia
plantilla y fechas. El estado `pausada` de la convocatoria y `pausado` del proceso
detienen el trabajo docente sin borrar nada.
Desde I-164 (migración `000160`) `silabos` ya no guarda `asignatura_id`: la materia se
lee del paralelo (paralelo → programación → materia; `Syllabus::subject` es derivado y
`Syllabus::forSubjects()` filtra por esa vía). Cada sílabo cubre exactamente un paralelo: `silabos.paralelo_id` es obligatorio y único
junto con `convocatoria_id`. La migración `000099` (I-131) retiró `alcances_silabo`
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
`reaperturas`, `ejecuciones_validacion`,
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
`validar_relaciones_flujo`. Las observaciones incluidas son las de
`observaciones_revision` con el mismo `revision_silabo_id`. I-116 eliminó
`preferencias_ia_carrera`: la IA de cada campo la decide Administración en la plantilla.

I-135 reemplazó las once tablas de IA (`ejecuciones_ia`, `revisiones_ia_silabo`,
`recomendaciones_ia`, `evidencias_ia`, `recomendacion_evidencias_ia`,
`retroalimentacion_ia`, `conversaciones_ia`, `mensajes_ia`, `evidencias_mensaje_ia`,
`citas_mensaje_ia` y `fragmentos_fuente_academica`) por dos, con el patrón estándar de
memoria de agente + almacén vectorial. I-146 conserva esas estructuras y retira las
operaciones nuevas del revisor y del índice. `historial_ia` guarda un mensaje JSONB por fila
(`id`, `sesion_id`, `mensaje_ia`, `enviado_en`), agrupado por sesión:

- Histórico retirado I-146 — `silabo:{id}:campo:{campo}`: mensajes `analisis` (entrada fijada, evidencia tipada,
  recomendaciones, estado, error) y `decision` (aceptada, ignorada, no útil o aplicada,
  con contenido anterior/posterior y versiones del borrador);
- Histórico retirado I-146 — `silabo:{id}:revision`: mensajes `revision` con los conteos agregados de sus análisis;
- `silabo:{id}:usuario:{usuario}`: el chat (`mensaje` con rol `usuario` o `asistente`,
  evidencia y citas) y los marcadores `ocultamiento` que limpian el panel sin borrar.

`mensaje_ia.entrada` es la entrada fijada: el trigger `proteger_historial_ia` impide
cambiarla y congela todo mensaje que salió de `pendiente`/`en_ejecucion`; solo el
reinicio institucional confirmado (I-118) puede borrar. Índices únicos parciales por
expresión conservan la idempotencia de análisis (sesión, clave, revisión), de revisiones
(sesión, `version_bloqueo_origen`), de preguntas del chat (sesión, clave) y de decisiones
(recomendación, persona, decisión; una sola `aplicada`). El progreso técnico vive en
`ejecuciones_trabajo` (`ia.chat`; `ia.analisis` queda retirado en I-146), cuyo `recurso_id` es el id del
mensaje. Al eliminar un sílabo, el trigger `eliminar_historial_ia_de_silabo` borra sus
sesiones. La evidencia sigue siendo una fotografía (etiqueta, extracto y huella)
inmutable aunque cambien luego el documento o el borrador.
`plantilla_silabo.revision_configuracion` aumenta ante cualquier cambio de estructura,
contenido o apariencia. Una preferencia de carrera solo es efectiva si el campo permite
la decisión de Coordinación y su `revision_plantilla_origen` coincide; de otro modo rige
inmediatamente el valor de Administración sin depender de una limpieza posterior.

### Operación e integración

`objetos_almacenados`, `artefactos_exportacion`, `notificaciones_internas`,
`eventos_auditoria` y `ejecuciones_trabajo`. La entrada del trabajo se guarda en la
misma transacción que el cambio funcional; después del commit se despacha el job.
Los archivos persistentes se describen mediante `objetos_almacenados`: por ejemplo,
`facultades.logo_objeto_id` referencia el objeto privado de su logo. La tabla de
dominio nunca almacena una ruta del disco como dato de negocio.
Los momentos funcionales se llaman respectivamente `almacenado_en`, `notificado_en`,
`ocurrido_en` y `encolado_en`; no se duplican con timestamps técnicos de Eloquent.
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

Toda llave foránea de una sola columna encabeza algún índice (I-133, migración
`000101`): PostgreSQL no los crea solo y, sin ellos, filtrar por la relación o borrar el
padre recorre la tabla hija. Una llave foránea nueva debe llegar con su índice.

Como mínimo, prueba/define:

- unicidad de identificadores institucionales no nulos;
- una sola coordinación activa por carrera mediante índice parcial único;
- identidad del sílabo canónico;
- número de revisión único por sílabo;
- versión única por plantilla y nombre de fuente único por carrera;
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

### Invariantes de IA conservados

I-146 mantiene las restricciones y la evidencia histórica. Las referencias siguientes a
análisis por campo, revisión integral, fuentes externas, recomendaciones y feedback
describen registros del revisor retirado, sin habilitar nuevas operaciones. El chat
vigente usa contexto académico/curricular y tablas de unidades/planificación, con
`context_version=curricular-units-v1`; no recupera fuentes ni aplica recomendaciones.

- la ejecución y el campo pertenecen al mismo sílabo (con plantilla única, I-132 retiró la
  comprobación de `plantilla_id`);
- una clave funcional activa es única por sílabo/campo, y una ejecución fallida permite
  una solicitud nueva sin reescribir historia;
- una revisión integral es única por sílabo/versión; cada campo aparece como máximo una
  vez dentro de ella y toda ejecución hija debe compartir sílabo y versión de bloqueo;
- evidencia solo se fija mientras la ejecución está pendiente; la externa debe provenir
  de una fuente activa, de igual carrera y fijada por la convocatoria, y la interna debe
  citar una definición de campo existente. El tipo determina de forma excluyente qué FK se
  admite y una fotografía nunca se reescribe;
- una conversación es única por docente y sílabo; una clave idempotente no crea dos
  preguntas dentro de la misma conversación;
- cada respuesta conversacional conserva evidencia propia y únicamente puede citar IDs
  de ese conjunto; limpiar la vista no borra el historial auditable;
- recomendaciones y sus citas solo se insertan mientras la ejecución está corriendo y
  deben pertenecer al mismo análisis/campo;
- una ejecución terminal y todos sus hijos son append-only; no admite evidencia o salida
  tardía;
- feedback solo referencia una ejecución completada; una aplicación por recomendación es
  única y exige `version_bloqueo_resultado = version_bloqueo_origen + 1`.

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
