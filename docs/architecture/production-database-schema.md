# Esquema objetivo de base de datos — Sílabos UEB

Describe el esquema resultante de las migraciones versionadas del repositorio. El estado
de un entorno productivo se comprueba por separado con el runbook de release.

Desde I-168 las restricciones siguen la convención `{tabla}_{columna}_not_null`,
`{tabla}_pkey` y `{tabla}_..._foreign`, y los índices llevan el prefijo de su tabla. La
base ya no usa la extensión `vector`.

# 1. Identidad y acceso

## usuarios

**Contexto:** cuentas de administrador, coordinador y docente.

~~~text
id UUID (PK)
nombre_usuario VARCHAR NOT NULL
correo_electronico VARCHAR NOT NULL UNIQUE
correo_verificado_en TIMESTAMPTZ NULL
contrasena VARCHAR NOT NULL
usuario_activo BOOLEAN NOT NULL
codigo_recordarme VARCHAR NULL
secreto_dos_factores TEXT NULL
codigos_recuperacion_dos_factores TEXT NULL
dos_factores_confirmado_en TIMESTAMPTZ NULL
~~~

## invitaciones_activacion

**Contexto:** enlaces de activación emitidos para cuentas aún no verificadas.

~~~text
id UUID (PK)
usuario_id UUID (FK → usuarios.id) NOT NULL
huella_activacion CHAR(64) NOT NULL UNIQUE
expira_en TIMESTAMPTZ NOT NULL
usada_en TIMESTAMPTZ NULL
invalidada_en TIMESTAMPTZ NULL
emitida_por_usuario_id UUID (FK → usuarios.id) NULL
creada_en TIMESTAMPTZ NOT NULL
~~~

## asignaciones_rol

**Contexto:** rol fijo otorgado a un usuario, global o por carrera.

~~~text
id UUID (PK)
usuario_id UUID (FK → usuarios.id) NOT NULL
rol VARCHAR NOT NULL
carrera_id UUID (FK → carreras.id) NULL
asignacion_rol_activa BOOLEAN NOT NULL
asignado_en TIMESTAMPTZ NOT NULL

CHECK (rol IN ('administrador', 'coordinador', 'docente', 'bibliotecario'))
CHECK (administrador/bibliotecario sin carrera; coordinador/docente con carrera)
UNIQUE parcial: usuario_id, rol y carrera_id cuando asignacion_rol_activa
~~~

## asignaciones_paralelo

**Contexto:** responsabilidad docente sobre un paralelo, respaldada por un rol docente
de la misma carrera.

~~~text
id UUID (PK)
asignacion_rol_id UUID (FK → asignaciones_rol.id) NOT NULL
paralelo_id UUID (FK → paralelos.id) NOT NULL
asignacion_paralelo_activa BOOLEAN NOT NULL
asignado_en TIMESTAMPTZ NOT NULL

UNIQUE parcial (paralelo_id) WHERE asignacion_paralelo_activa
~~~

## sesiones

**Contexto:** sesión web de Laravel.

~~~text
id VARCHAR (PK)
user_id UUID (FK → usuarios.id) NULL
ip_address VARCHAR NULL
user_agent TEXT NULL
payload TEXT NOT NULL
last_activity INTEGER NOT NULL
~~~

## restablecimientos_contrasena

**Contexto:** tokens de recuperación.

~~~text
email VARCHAR (PK)
token VARCHAR NOT NULL
created_at TIMESTAMPTZ NULL
~~~

### Cardinalidades

~~~text
usuarios N:M carreras por roles con alcance en asignaciones_rol
asignaciones_rol (docente) 1:N asignaciones_paralelo
paralelos 1:N asignaciones_paralelo históricas; máximo 1 asignación activa
usuarios 1:N sesiones
~~~

---

# 2. Estructura académica

## facultades

**Contexto:** facultades institucionales.

~~~text
id UUID (PK)
nombre_facultad VARCHAR NOT NULL
facultad_activa BOOLEAN NOT NULL
campus_facultad VARCHAR NOT NULL CHECK (campus_facultad IN (
  'Campus Matriz Dr. Gabriel Galarza López',
  'Campus Laguacoto',
  'Campus CENI (Centro de Eventos Nacionales e Internacionales)',
  'Extensión San Miguel',
  'Sede Chimbo'
))
logo_objeto_id UUID (FK → objetos_almacenados.id) NULL UNIQUE
~~~

## carreras

**Contexto:** carreras académicas.

~~~text
id UUID (PK)
facultad_id UUID (FK → facultades.id) NOT NULL
codigo_carrera VARCHAR NOT NULL UNIQUE
nombre_carrera VARCHAR NOT NULL
carrera_activa BOOLEAN NOT NULL
modalidad_carrera VARCHAR NULL
cantidad_ciclos_malla SMALLINT NOT NULL

CHECK modalidad_carrera IN ('presencial', 'semipresencial', 'en_linea', 'a_distancia', 'hibrida')
~~~

## periodos_academicos

**Contexto:** períodos lectivos.

~~~text
id UUID (PK)
codigo_periodo_academico VARCHAR NOT NULL UNIQUE
fecha_inicio_periodo DATE NOT NULL
fecha_fin_periodo DATE NOT NULL
cantidad_semanas_lectivas SMALLINT NOT NULL
~~~

## asignaturas

**Contexto:** materias de la estructura curricular única de una carrera.

~~~text
id UUID (PK)
carrera_id UUID (FK → carreras.id) NOT NULL
codigo_asignatura VARCHAR NOT NULL
nombre_asignatura VARCHAR NOT NULL
ciclo_asignatura SMALLINT NULL
creditos_asignatura NUMERIC NULL
total_horas_asignatura SMALLINT NULL
asignatura_activa BOOLEAN NOT NULL
horas_ac NUMERIC NULL
horas_pae NUMERIC NULL
horas_aa NUMERIC NULL
orden_asignatura_en_ciclo SMALLINT NOT NULL
unidad_organizativa_curricular_asignatura VARCHAR NULL
modalidad_asignatura VARCHAR NULL

UNIQUE (carrera_id, codigo_asignatura)
CHECK modalidad_asignatura nula o con los mismos valores que modalidad_carrera
~~~

## requisitos_asignatura

**Contexto:** relación de requisito entre dos asignaturas.

~~~text
id UUID (PK)
asignatura_id UUID (FK → asignaturas.id) NOT NULL
requisito_id UUID (FK → asignaturas.id) NOT NULL
tipo_requisito_asignatura VARCHAR NOT NULL

UNIQUE (asignatura_id, requisito_id)
CHECK tipo_requisito_asignatura IN ('prerrequisito', 'correquisito')
CHECK asignatura_id <> requisito_id
~~~

## programaciones_asignatura

**Contexto:** programación de una asignatura en período. Campus y modalidad se derivan de
la materia, su carrera y su facultad (I-164); no se guardan aquí.

~~~text
id UUID (PK)
periodo_academico_id UUID (FK → periodos_academicos.id) NOT NULL
asignatura_id UUID (FK → asignaturas.id) NOT NULL
programacion_asignatura_activa BOOLEAN NOT NULL

UNIQUE (periodo_academico_id, asignatura_id)
~~~

## paralelos

**Contexto:** paralelos de una programación.

~~~text
id UUID (PK)
programacion_asignatura_id UUID (FK → programaciones_asignatura.id) NOT NULL
codigo_paralelo VARCHAR NOT NULL
jornada_paralelo VARCHAR NULL

UNIQUE (programacion_asignatura_id, codigo_paralelo)
~~~

### Cardinalidades

~~~text
facultades 1:N carreras
carreras 1:N asignaturas
asignaturas 1:N requisitos_asignatura, como asignatura y como requisito
periodos_academicos 1:N programaciones_asignatura
asignaturas 1:N programaciones_asignatura
programaciones_asignatura 1:N paralelos
~~~

---

# 3. Plantilla institucional y fuentes

## plantilla_silabo

**Contexto:** plantilla institucional que define el documento de sílabo. Desde I-164
(migración `000161`) las secciones y campos viven en tablas propias; aquí quedan solo la
apariencia (`mapeo_documento_plantilla`). I-168 retiró el contador
`revision_configuracion` y sus triggers `tocar_revision_*`.

~~~text
id UUID (PK)
mapeo_documento_plantilla JSONB NULL

UNIQUE constante: existe solo una plantilla
~~~

## secciones_plantilla

**Contexto:** sección de la plantilla (I-164; antes embebida en `documento_plantilla`).
I-166 da nombres explícitos a sus columnas.

~~~text
id UUID (PK)
plantilla_id UUID (FK → plantilla_silabo.id, ON DELETE CASCADE) NOT NULL
clave_seccion_plantilla VARCHAR(120) NOT NULL
titulo_seccion_plantilla VARCHAR(180) NOT NULL
descripcion_seccion_plantilla TEXT NULL
posicion_seccion_plantilla SMALLINT NOT NULL

UNIQUE (plantilla_id, clave_seccion_plantilla)
~~~

## campos_plantilla

**Contexto:** campo de una sección (Texto libre o Personalizado). Se llamó
`bloques_plantilla` hasta I-166 (migración `000165`), que también nombró sus columnas de
forma explícita. `configuracion_campo_plantilla` guarda
`content_type`, `page_orientation`, el diseño de la hoja (`document`, JSON de ProseMirror),
`options_sources` y `detached_fields`: el diseño es un documento y sigue en JSON a
propósito. Desde I-168 cada nodo `field` de la hoja guarda solo `key`, `choice` y
`listStyle`; los atributos del campo (etiqueta, tipo, opciones, `showWhen`, origen de
opciones) viven una sola vez en `definiciones_campo`. `TemplateFieldAttributes` los
completa al leer y los recorta al guardar.

~~~text
id UUID (PK)
seccion_plantilla_id UUID (FK → secciones_plantilla.id, ON DELETE CASCADE) NOT NULL
plantilla_id UUID (FK → plantilla_silabo.id, ON DELETE CASCADE) NOT NULL
clave_campo_plantilla VARCHAR(120) NOT NULL
titulo_campo_plantilla VARCHAR(180) NOT NULL DEFAULT ''
posicion_campo_plantilla SMALLINT NOT NULL
configuracion_campo_plantilla JSONB NOT NULL

UNIQUE (plantilla_id, clave_campo_plantilla)
FK compuesta (seccion_plantilla_id, plantilla_id): la sección es de la misma plantilla
~~~

## definiciones_campo

**Contexto:** cada pregunta o dato que guarda un campo de la plantilla, con sus reglas.
Es la única copia de sus atributos (I-168).

~~~text
id UUID (PK)
campo_plantilla_id UUID (FK → campos_plantilla.id, ON DELETE CASCADE) NOT NULL
clave_definicion_campo VARCHAR NOT NULL
etiqueta_definicion_campo VARCHAR NOT NULL
ayuda_definicion_campo TEXT NULL
tipo_definicion_campo VARCHAR NOT NULL
heredado_definicion_campo BOOLEAN NOT NULL
origen_maestro_definicion_campo VARCHAR NULL
editable_docente_definicion_campo BOOLEAN NOT NULL
opciones_definicion_campo JSONB NULL
posicion_definicion_campo SMALLINT NOT NULL
plantilla_id UUID (FK → plantilla_silabo.id) NOT NULL
mostrar_si_definicion_campo JSONB NULL

UNIQUE (plantilla_id, clave_definicion_campo)
UNIQUE (campo_plantilla_id, posicion_definicion_campo)
FK compuesta (campo_plantilla_id, plantilla_id): el campo es de la misma plantilla
CHECK heredado: si heredado_definicion_campo, hay origen maestro y no es editable
TRIGGER validar_condicion_definicion: mostrar_si apunta a una definición válida
TRIGGER propagar_clave_condicion: al renombrar una clave, actualiza las condiciones que la usan
~~~

## fuentes_academicas

**Contexto:** documento interno de ingesta curricular de una carrera. I-146 retira su
CRUD/editor y su selección para revisión IA; conserva documentos y archivos privados
para importaciones I-145 y evidencia histórica. I-168 retira la descripción, el texto
derivado y la versión del documento.

~~~text
id UUID (PK)
carrera_id UUID (FK → carreras.id) NOT NULL
nombre_fuente_academica VARCHAR NOT NULL
fuente_academica_activa BOOLEAN NOT NULL
documento_fuente_academica JSONB NOT NULL
objeto_pdf_id UUID (FK → objetos_almacenados.id) NULL UNIQUE
estado_ingesta_fuente VARCHAR(24) NOT NULL DEFAULT 'manual'
ingesta_actualizada_en TIMESTAMPTZ NULL

UNIQUE (carrera_id, nombre_fuente_academica)

CHECK estado_ingesta_fuente IN ('manual', 'pendiente', 'en_ejecucion', 'completada', 'fallida')
CHECK documento_fuente_academica es un objeto doc con content como arreglo
CHECK documento_fuente_academica no contiene nodos de texto vacíos
~~~

### Cardinalidades

~~~text
plantilla_silabo 1:N definiciones_campo
carreras 1:N fuentes_academicas
fuentes_academicas 1:0..1 objetos_almacenados como PDF vigente
fuentes_academicas 1:N ejecuciones_trabajo por recurso lógico
~~~

---

# 4. Convocatorias y borradores

## convocatorias_universidad

**Contexto:** proceso universitario de elaboración por período.

~~~text
id UUID (PK)
inicia_en TIMESTAMPTZ NOT NULL
entrega_en TIMESTAMPTZ NOT NULL
estado_convocatoria_universidad VARCHAR NOT NULL
plantilla_id UUID (FK → plantilla_silabo.id) NOT NULL
periodo_academico_id UUID (FK → periodos_academicos.id) NOT NULL

UNIQUE (periodo_academico_id)
UNIQUE parcial: una convocatoria abierta o pausada
~~~

## convocatorias_carreras

**Contexto:** participación de una carrera en una convocatoria.

~~~text
id UUID (PK)
carrera_id UUID (FK → carreras.id) NOT NULL
estado_convocatoria_carrera VARCHAR NOT NULL
convocatoria_universidad_id UUID (FK → convocatorias_universidad.id) NOT NULL

UNIQUE (carrera_id, convocatoria_universidad_id)
~~~

## silabos

**Contexto:** borrador vivo de un sílabo.

~~~text
id UUID (PK)
convocatoria_carrera_id UUID (FK → convocatorias_carreras.id) NOT NULL
paralelo_id UUID (FK → paralelos.id) NOT NULL
estado_silabo VARCHAR NOT NULL
version_bloqueo INTEGER NOT NULL
iniciado_en TIMESTAMPTZ NULL
guardado_en TIMESTAMPTZ NULL
contexto_academico_silabo JSONB NOT NULL
plantilla_id UUID (FK → plantilla_silabo.id) NOT NULL
~~~

`UNIQUE (convocatoria_carrera_id, paralelo_id)`. La carrera se obtiene de
`convocatorias_carreras.carrera_id` y la materia programada de
`paralelos.programacion_asignatura_id`; I-131 (`000099`) retiró `alcances_silabo` y
`silabos.carrera_id`, e I-164 (`000160`) retiró `silabos.asignatura_id`, que duplicaba la
materia del paralelo.

## docentes_vigentes_silabo (vista)

**Contexto:** docente vigente de un sílabo (I-130, migración `000098`, que retira la
tabla `colaboradores_silabo`; I-131 la recrea en `000099`). Se deriva de
`silabos.paralelo_id` → `asignaciones_paralelo` activas → `asignaciones_rol` activas con rol `docente`. Solo lectura.

~~~text
silabo_id UUID
usuario_id UUID
asignacion_rol_id UUID
asignacion_paralelo_id UUID
paralelo_id UUID
~~~

## valores_campo

**Contexto:** valor no repetible de un campo dentro de un sílabo.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
definicion_campo_id UUID (FK → definiciones_campo.id) NOT NULL
valor_campo JSONB NULL

UNIQUE (silabo_id, definicion_campo_id)
~~~

## filas_repetibles (retirada)

I-156 la elimina (migración `000157`): lo que el docente escribía por filas se convirtió
en Texto libre (`valores_campo.valor_campo`, documento JSON) antes de borrarla.

## Validación del borrador (retirada)

I-176 elimina `validaciones_silabo` y `resultados_validacion`: la validación es una función
del contenido actual, se calcula al validar o enviar y se devuelve sin guardar. La auditoría
conserva el evento `silabo.validado`.

### Cardinalidades

~~~text
periodos_academicos 1:0..1 convocatorias_universidad
plantilla_silabo 1:N convocatorias_universidad
convocatorias_universidad 1:N convocatorias_carreras
carreras 1:N convocatorias_carreras
convocatorias_carreras usa todas las fuentes_academicas activas de su carrera (sin tabla intermedia)
convocatorias_carreras 1:N fechas_limite_convocatoria
convocatorias_carreras 1:N silabos
paralelos 1:N silabos (uno por convocatoria de carrera)
silabos N:M usuarios mediante la vista docentes_vigentes_silabo (derivada del paralelo y sus asignaciones)
silabos N:M definiciones_campo mediante valores_campo
~~~

---

# 5. Trabajos y chat de IA

## ejecuciones_trabajo

**Contexto:** única tabla funcional para trabajos asíncronos y notificaciones; conserva
la entrada, idempotencia, estado, progreso, intentos, resultado y error.

~~~text
id UUID (PK)
tipo_ejecucion_trabajo VARCHAR NOT NULL
estado_ejecucion_trabajo VARCHAR NOT NULL
clave_idempotencia VARCHAR NOT NULL UNIQUE
correlacion_id UUID NULL
intentos SMALLINT NOT NULL
progreso SMALLINT NOT NULL
resultado_ejecucion_trabajo JSONB NULL
codigo_error VARCHAR NULL
mensaje_error TEXT NULL
iniciado_en TIMESTAMPTZ NULL
finalizado_en TIMESTAMPTZ NULL
encolado_en TIMESTAMPTZ NULL
cola VARCHAR NOT NULL
tipo_recurso VARCHAR NULL
recurso_id UUID NULL
entrada_ejecucion_trabajo JSONB NULL
intentos_maximos SMALLINT NOT NULL
~~~

## historial_ia

**Contexto:** memoria de la IA (I-135). Cada fila es un mensaje JSON de una sesión;
reemplaza a `ejecuciones_ia`, `revisiones_ia_silabo`, `evidencias_ia`,
`recomendaciones_ia`, `recomendacion_evidencias_ia`, `retroalimentacion_ia`,
`conversaciones_ia`, `mensajes_ia`, `evidencias_mensaje_ia` y `citas_mensaje_ia`.
Desde I-168 solo guarda el chat: los mensajes de análisis, revisión y decisión del
revisor retirado ya no se admiten.

~~~text
id UUID (PK)
sesion_id TEXT NOT NULL
mensaje_ia JSONB NOT NULL
enviado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()

CHECK sesion_id ~ 'silabo:{uuid}:usuario:{uuid}'
CHECK mensaje_ia objeto con entrada objeto,
  tipo IN ('mensaje', 'ocultamiento'),
  estado IN ('pendiente', 'en_ejecucion', 'completado', 'fallido', 'registrada')
Índice btree (sesion_id, enviado_en)
UNIQUE parcial pregunta de chat: (sesion_id, entrada.clave_idempotencia)
Trigger proteger_historial_ia: entrada inmutable; mensaje terminal inmutable; sin
  borrado salvo reinicio institucional (I-118)
Trigger eliminar_historial_ia_de_silabo (en silabos): borra las sesiones del sílabo
~~~

Forma de `mensaje_ia` por tipo:

~~~text
mensaje    usuario:   {tipo, rol, estado: completado, entrada{contenido,
                       clave_idempotencia, version_bloqueo_origen, usuario_id}}
           asistente: {tipo, rol, estado, entrada{respuesta_a_id,
                       version_bloqueo_origen, ejecucion_trabajo_id, evidencia[...]},
                       contenido, citas[ids], version_pasarela_ejecutada,
                       codigo_error, mensaje_error, completado_en}
ocultamiento {tipo, estado: registrada, entrada{usuario_id}}
~~~

### Cardinalidades

~~~text
silabos 1:N historial_ia mediante el prefijo de sesion_id
ejecuciones_trabajo 1:0..1 historial_ia (recurso_id = id del mensaje)
~~~

---

# 6. Revisión, aprobación y corrección

## revisiones_silabo

**Contexto:** fotografía inmutable enviada para revisión.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
revision_anterior_id UUID (FK → revisiones_silabo.id) NULL
numero_revision SMALLINT NOT NULL
clave_idempotencia UUID NOT NULL
version_bloqueo_origen INTEGER NOT NULL
fotografia_revision_silabo JSONB NOT NULL
huella_sha256 CHAR(64) NOT NULL
enviado_por UUID (FK → usuarios.id) NOT NULL
enviado_en TIMESTAMPTZ NOT NULL
correccion_justificacion TEXT NULL
correccion_etapa VARCHAR(16) NULL CHECK (biblioteca | coordinacion | decanato)
correccion_solicitada_por UUID (FK → usuarios.id) NULL INDEX
correccion_solicitada_en TIMESTAMPTZ NULL

UNIQUE (silabo_id, numero_revision)
UNIQUE (silabo_id, clave_idempotencia)

CHECK (las cuatro columnas correccion_* son todas nulas o todas presentes)
~~~

Las columnas `correccion_*` son de escritura única (I-176): registran la solicitud formal
de corrección de esa revisión, con la etapa que la pidió. Reemplazan a la tabla
`solicitudes_correccion`. La reapertura ya no se enlaza desde la revisión: la revisión
siguiente apunta a la aprobada con `revision_anterior_id`.

## Observaciones por campo (retiradas)

I-177 elimina `observaciones_revision`: la revisión se devuelve con un comentario general
guardado en `revisiones_silabo.correccion_justificacion`. Biblioteca elige motivos
predefinidos y el sistema guarda su texto en ese mismo campo.

## aprobaciones

**Contexto:** aprobación de una revisión.

~~~text
id UUID (PK)
revision_silabo_id UUID (FK → revisiones_silabo.id) NOT NULL UNIQUE
clave_idempotencia UUID NOT NULL
huella_sha256 CHAR(64) NOT NULL
aprobado_por UUID (FK → usuarios.id) NOT NULL
aprobado_en TIMESTAMPTZ NOT NULL
aprobacion_base_id UUID (FK → aprobaciones.id) NULL INDEX
causa_reapertura TEXT NULL
origen_reapertura VARCHAR(16) NULL CHECK (coordinacion | biblioteca | decanato)
reabierta_por UUID (FK → usuarios.id) NULL INDEX
reabierta_en TIMESTAMPTZ NULL
clave_reapertura UUID NULL

CHECK (las cinco columnas de reapertura son todas nulas o todas presentes)
~~~

El sílabo se obtiene de `revisiones_silabo.silabo_id`. La clave de idempotencia sigue
siendo única por sílabo; la comprueba el trigger `validar_relaciones_flujo` (I-131).
`aprobacion_base_id` solo existe en la aprobación heredada que crea el reenvío de una
corrección de biblioteca (I-165): apunta a la aprobación de coordinación que cubre todo
el contenido salvo la bibliografía, y conserva su aprobador.

Las columnas de reapertura son de escritura única (I-176): una aprobación se reabre una
sola vez y reemplazan a la tabla `reaperturas`. Una aprobación con `reabierta_en` queda
anulada para firmas. La clave de reapertura es única por sílabo (`validar_relaciones_flujo`).

## envios_biblioteca

**Contexto:** envío de una revisión aprobada a biblioteca y su visto bueno (I-165).

~~~text
id UUID (PK)
revision_silabo_id UUID (FK → revisiones_silabo.id) NOT NULL UNIQUE
enviado_por UUID (FK → usuarios.id) NOT NULL INDEX
enviado_en TIMESTAMPTZ NOT NULL
verificado_por UUID (FK → usuarios.id) NULL INDEX
verificado_en TIMESTAMPTZ NULL

CHECK (verificado_por y verificado_en ambos nulos o ambos presentes)
~~~

El trigger `proteger_envio_biblioteca` solo permite registrar la verificación una vez
(migración `000164`). Estado derivado: `firmado` (firma `biblioteca` en un PDF de la
revisión), `comentado` (corrección pedida por biblioteca), `anulado` (aprobación
reabierta), `por_firmar` (verificado) o `pendiente`.

## Cambios de estado del sílabo

Desde I-136 no existe `transiciones_silabo`: cada cambio de estado queda en
`eventos_auditoria` (`silabo.enviado`, `silabo.reenviado`, `silabo.correccion_solicitada`,
`silabo.aprobado`, `silabo.reabierto`) con `from_state` y `to_state` en sus metadatos.

### Cardinalidades

~~~text
silabos 1:N revisiones_silabo
revisiones_silabo 0..1:N revisiones_silabo mediante revision_anterior_id
la solicitud de corrección (columnas correccion_*) es un comentario general de la revisión
revisiones_silabo 1:0..1 aprobaciones
la reapertura (columnas de aprobaciones) deja la aprobación anulada
~~~

---

# 7. Archivos, auditoría y operación

## objetos_almacenados

**Contexto:** metadatos de archivos privados; su contenido no está en la BD. Además del
PDF original y los artefactos exportados, clasificaciones como
`academic_source_image` identifican imágenes raster derivadas de una fuente académica.

~~~text
id UUID (PK)
disco VARCHAR NOT NULL
ruta_interna VARCHAR NOT NULL
nombre_logico VARCHAR NOT NULL
mime VARCHAR NOT NULL
tamano_bytes BIGINT NOT NULL
huella_sha256 CHAR(64) NOT NULL
clasificacion VARCHAR NOT NULL
estado_objeto_almacenado VARCHAR NOT NULL CHECK (= 'activo')
propietario_usuario_id UUID (FK → usuarios.id) NULL
carrera_id UUID (FK → carreras.id) NULL
almacenado_en TIMESTAMPTZ NOT NULL

UNIQUE (disco, ruta_interna)
~~~

## artefactos_exportacion

**Contexto:** exportaciones DOCX/PDF de revisiones de sílabo.

~~~text
id UUID (PK)
revision_silabo_id UUID (FK → revisiones_silabo.id) NOT NULL
ejecucion_trabajo_id UUID (FK → ejecuciones_trabajo.id) NULL UNIQUE
objeto_docx_id UUID (FK → objetos_almacenados.id) NULL UNIQUE
objeto_pdf_id UUID (FK → objetos_almacenados.id) NULL UNIQUE
version_renderizador VARCHAR NOT NULL
idioma VARCHAR NOT NULL
clave_idempotencia UUID NOT NULL
estado_artefacto_exportacion VARCHAR NOT NULL
solicitado_por UUID (FK → usuarios.id) NOT NULL
solicitado_en TIMESTAMPTZ NOT NULL
completado_en TIMESTAMPTZ NULL
plantilla_id UUID (FK → plantilla_silabo.id) NOT NULL

UNIQUE (revision_silabo_id, clave_idempotencia)
INDEX (revision_silabo_id, estado_artefacto_exportacion, solicitado_en)
~~~

El sílabo se obtiene de la revisión exportada (I-131 retiró `silabo_id`). I-168 retiró
`asignacion_rol_id` y simplificó el trigger `validar_artefacto_exportacion`.

## firmas_documento

**Contexto:** firma electrónica de un PDF exportado por docente, coordinación o
biblioteca.

~~~text
id UUID (PK)
artefacto_exportacion_id UUID (FK → artefactos_exportacion.id) NOT NULL
rol_firma VARCHAR(16) NOT NULL
usuario_id UUID (FK → usuarios.id) NOT NULL INDEX
objeto_almacenado_id UUID (FK → objetos_almacenados.id) NOT NULL UNIQUE
certificado_titular VARCHAR NOT NULL
certificado_serie VARCHAR NOT NULL
certificado_emisor VARCHAR NOT NULL
certificado_vence_en TIMESTAMPTZ NOT NULL
huella_sha256 CHAR(64) NOT NULL
firmado_en TIMESTAMPTZ NOT NULL

CHECK rol_firma IN ('docente', 'coordinacion', 'biblioteca')
UNIQUE (artefacto_exportacion_id, rol_firma)
TRIGGER proteger_firma_documento: solo se insertan filas; no se editan ni borran
~~~

## notificaciones_internas

**Contexto:** notificaciones visibles dentro de la aplicación.

~~~text
id UUID (PK)
usuario_id UUID (FK → usuarios.id) NOT NULL
clave_deduplicacion VARCHAR NOT NULL
tipo_notificacion_interna VARCHAR NOT NULL
titulo_notificacion_interna VARCHAR NOT NULL
mensaje_notificacion_interna TEXT NOT NULL
tipo_recurso VARCHAR NULL
recurso_id UUID NULL
leido_en TIMESTAMPTZ NULL
notificado_en TIMESTAMPTZ NOT NULL

UNIQUE (usuario_id, clave_deduplicacion)
~~~

## eventos_auditoria

**Contexto:** trazabilidad de acciones relevantes.

~~~text
id UUID (PK)
actor_usuario_id UUID (FK → usuarios.id) NULL
asignacion_rol_id UUID (FK → asignaciones_rol.id) NULL
accion_evento_auditoria VARCHAR NOT NULL
tipo_recurso VARCHAR NOT NULL
recurso_id UUID NULL
resultado_evento_auditoria VARCHAR NOT NULL CHECK (IN ('exito', 'fallido'))
metadatos_evento_auditoria JSONB NULL
correlacion_id UUID NULL
ocurrido_en TIMESTAMPTZ NOT NULL
~~~

## trabajos_fallidos

**Contexto:** registro técnico de Laravel para trabajos agotados; no es una tabla
funcional del dominio.

~~~text
id BIGINT (PK)
uuid VARCHAR NOT NULL UNIQUE
connection VARCHAR NOT NULL
queue VARCHAR NOT NULL
payload TEXT NOT NULL
exception TEXT NOT NULL
failed_at TIMESTAMP NOT NULL
~~~

## migraciones

**Contexto:** historial de migraciones aplicadas.

~~~text
id INTEGER (PK)
migration VARCHAR NOT NULL
batch INTEGER NOT NULL
~~~

### Cardinalidades

~~~text
usuarios 1:N objetos_almacenados como propietario
carreras 1:N objetos_almacenados
revisiones_silabo 1:N artefactos_exportacion
artefactos_exportacion 1:N firmas_documento (una por rol)
ejecuciones_trabajo 1:0..1 artefactos_exportacion
objetos_almacenados 1:0..1 artefactos_exportacion como DOCX
objetos_almacenados 1:0..1 artefactos_exportacion como PDF
objetos_almacenados 1:0..1 facultades como logo
usuarios 1:N notificaciones_internas
usuarios 1:N eventos_auditoria
~~~

---
