# PostgreSQL y persistencia

## Convenciones

- **idioma (I-28)**: todos los identificadores del esquema (tablas, columnas, funciones,
  triggers) y los valores de estado almacenados van **en español**, con grafía sin tilde
  (`silabos`, `numero_revision`, `decision`). Las siglas técnicas se conservan (`id`,
  `uuid`, `mime`, `url`, `ip`, `sha256`, `docx`, `pdf`) igual que los nombres propios de
  formato (`markdown`, `es-EC`). Única excepción: las columnas internas de las tablas
  que los drivers de Laravel escriben con nombres fijos (`sesiones`,
  `restablecimientos_contrasena`, `migraciones`) — registrada como
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
`paralelos`, `asignaciones_paralelo`.

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
I-181 retiró `asignaturas_ofertadas`: el paralelo apunta directo al período y a la asignatura
(`paralelos.periodo_academico_id`, `paralelos.asignatura_id`), porque siempre se ofrecen todas
las materias de la malla. La modalidad no es tabla sino
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
participa en un sílabo, se conserva. Su operatividad se deriva del período, sin duplicar un
booleano individual. Todo paralelo tiene código único por período y materia y `jornada_paralelo` obligatoria (`matutina`, `vespertina` o
`nocturna`); la migración `000112` rellenó los nulos con `matutina` y dejó la columna
NOT NULL (I-141).

La estructura curricular no es una entidad separada: `carreras.cantidad_ciclos_malla`
define la cantidad de ciclos de la única estructura de esa carrera. El código obligatorio
de identidad es `carreras.codigo_carrera`; no existe código de malla.
`asignaturas.carrera_id` las relaciona directamente. Los
atributos académicos de `asignaturas` son fijos y tipados: ninguna carrera agrega
columnas o redefine esos atributos mediante valores EAV. ACD, APE, AA, créditos y total son un
conjunto fijo; `total_horas_asignatura` es una columna generada `STORED` igual a
`round(horas_ac + horas_pae + horas_aa)` (I-132) y no se escribe desde la aplicación.
I-187 creó la ficha de asignatura y I-189 la dejó sin tabla propia: sus valores son filas de
`valores_campo` con `asignatura_id` en lugar de `silabo_id` (un `CHECK` exige exactamente uno
de los dos; un índice único parcial por dueño). Los campos de la plantilla con origen `ficha`
forman el formulario que Coordinación llena una vez por materia; el sílabo copia el valor al
crearse. Corregir la ficha no reescribe borradores ni fotografías. Agregar un campo nuevo a la
ficha es solo configuración de la plantilla (sin migración).

`silabos.contexto_academico_silabo` (alias `contexto_academico`) guarda la lectura JSON de la
estructura curricular, la asignatura y su programación al crear el
expediente. No es evidencia inmutable: `InProgressWork` la vuelve a sincronizar cuando una
corrección autorizada con el ámbito pausado cambia la carrera, la materia o su programación,
y `ResetSyllabus` la reconstruye. La evidencia inmutable es
`revisiones_silabo.fotografia_revision_silabo`. No sustituye las relaciones transaccionales
ni permite reconstruir autorizaciones.

I-144 guardaba resultados de aprendizaje en `bases_resultados_asignatura` y
`resultados_aprendizaje_asignatura`; I-147 retiró su escritura y I-168 (migración `000166`)
eliminó ambas tablas, que estaban vacías.

### Proyecto de carrera retirado (I-169)

La migración `2026_09_30_000182_remove_career_project_data` eliminó
`conceptos_curriculares`, `valores_curriculares`, `importaciones_curriculares` y
`documentos_importacion` (antes `fuentes_academicas`), con sus funciones
`proteger_forma_concepto_curricular` y `validar_tipo_valor_curricular`, los archivos
`curricular_*`/`academic_source_*`, las variables `curricular_*` y de resultados de
aprendizaje de las hojas, `configuracion.options_sources` y la clave `curricular` de
`silabos.datos_academicos_silabo`. Lo que antes venía del Proyecto de carrera ahora lo
escribe el docente en campos que el administrador pone en la plantilla; los campos de
selección solo tienen opciones fijas.

### Plantilla y documentos internos de ingesta

`plantilla_silabo`, `secciones_plantilla`, `campos_plantilla` y `definiciones_campo`. I-168 eliminó `documentos_ia`, su trigger y la extensión `vector`: la
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
`listStyle`).
`TemplateFieldAttributes` completa los nodos al leer (`SyllabusTemplate::loadSections`) y
los recorta al guardar (`syncDocument`), así que el editor y las fotografías siguen
recibiendo el nodo completo. `configuracion.detached_fields` conserva, por clave, si un
campo retirado de la hoja era editable, para restaurarlo si vuelve.

`definiciones_campo.mostrar_si_definicion_campo` (alias `mostrar_si`) guarda
`{key, value}` apuntando a otro campo de la misma plantilla por su clave. Desde I-168 la
base lo protege como una FK: el trigger diferido `validar_condicion_definicion` rechaza
una clave inexistente y `propagar_clave_condicion` anula la condición si el campo
referido se borra o la actualiza si cambia de clave. Un `CHECK`
(`definiciones_campo_heredado_check`) exige que un campo heredado tenga origen. Solo
los campos con origen `ficha` pueden ser heredados y, si el administrador lo decide,
editables por el docente. Las columnas de `definiciones_campo` y otras renombradas en I-168
usan su nombre completo (`heredado_definicion_campo`, `origen_maestro_definicion_campo`,
`editable_docente_definicion_campo`, `mostrar_si_definicion_campo`,
`convocatorias_carreras.convocatoria_universidad_id`, `silabos.convocatoria_carrera_id`,
`asignaciones_paralelo.asignacion_paralelo_activa`,
`ejecuciones_validacion.cantidad_errores_validacion`); los modelos conservan el nombre corto
como alias. I-168 retiró `ia_habilitada` y `marcador_documento` (se escribían y nadie los
leía), `valores_campo.heredado` y `origen_valor_campo` (copias de la definición) y
`plantilla_silabo.revision_configuracion` con sus cuatro triggers `tocar_revision_*`.
No hay reglas por campo: `reglas_definicion_campo` se eliminó (migración `000111`). Todo
campo del docente es obligatorio al enviar salvo las dos bibliografías, que I-187 vuelve
opcionales; el estado `borrador` puede quedar incompleto.

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
(la materia se deriva de `paralelos.asignatura_id`) y
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

`revisiones_silabo`, `aprobaciones`, `envios_biblioteca` (I-165)
y la memoria de IA `historial_ia` (I-135). I-177 eliminó `observaciones_revision`: no hay
observaciones por campo; el revisor devuelve con un comentario general (biblioteca, con
motivos predefinidos) guardado en `correccion_*`. El docente corrige y reenvía.
Desde I-131, `aprobaciones` y `artefactos_exportacion` no guardan `silabo_id`: el sílabo se
obtiene de la revisión (`revision_silabo_id`). La unicidad por sílabo de las claves de
idempotencia de aprobaciones la verifica `validar_relaciones_flujo`.
Desde I-136 no existe `transiciones_silabo`: los cambios de estado del sílabo se
registran solo en `eventos_auditoria`, con `from_state`/`to_state` en los metadatos.
I-176 retiró `validaciones_silabo`, `resultados_validacion`, `solicitudes_correccion` y
`reaperturas`. La validación se calcula al enviar o validar y no se guarda. La solicitud de
corrección son columnas `correccion_*` de `revisiones_silabo`, de escritura única: pasan de
NULL a un valor una vez, completas, y el trigger `proteger_registro_con_campos_unicos`
mantiene inmutable el resto de la fila (ADR 0002). I-178 retiró la reapertura: las columnas
que I-176 había puesto en `aprobaciones` se eliminaron y esa tabla es totalmente inmutable. I-116 eliminó
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
soportada: `sesiones` (driver de sesión `database`), `restablecimientos_contrasena` (broker de recuperación)
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

El chat usa el contexto académico de la asignatura y el Texto libre del sílabo, con
`context_version=free-text-v3`; no recupera fuentes ni aplica recomendaciones.

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
