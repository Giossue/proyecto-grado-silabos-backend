# Esquema objetivo de base de datos — Sílabos UEB

Describe el esquema resultante de las migraciones versionadas del repositorio. El estado
de un entorno productivo se comprueba por separado con el runbook de release.

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
asignado_en TIMESTAMPTZ NULL

CHECK (rol IN ('administrador', 'coordinador', 'docente'))
CHECK (administrador sin carrera; coordinador/docente con carrera)
UNIQUE parcial: usuario_id, rol y carrera_id cuando asignacion_rol_activa
~~~

## asignaciones_paralelo

**Contexto:** responsabilidad docente sobre un paralelo, respaldada por un rol docente
de la misma carrera.

~~~text
id UUID (PK)
asignacion_rol_id UUID (FK → asignaciones_rol.id) NOT NULL
paralelo_id UUID (FK → paralelos.id) NOT NULL
docente_paralelo_activo BOOLEAN NOT NULL
asignado_en TIMESTAMPTZ NULL

UNIQUE parcial (paralelo_id) WHERE docente_paralelo_activo
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
horas_proyecto NUMERIC NULL
horas_ap NUMERIC NULL
horas_ac NUMERIC NULL
horas_pae NUMERIC NULL
horas_aa NUMERIC NULL
horas_paec NUMERIC NULL
orden_asignatura_en_ciclo SMALLINT NOT NULL
unidad_organizativa_curricular_asignatura VARCHAR NULL
modalidad_asignatura VARCHAR NULL

UNIQUE (carrera_id, codigo_asignatura)
~~~

## requisitos_asignatura

**Contexto:** relación de requisito entre dos asignaturas.

~~~text
id UUID (PK)
asignatura_id UUID (FK → asignaturas.id) NOT NULL
requisito_id UUID (FK → asignaturas.id) NOT NULL
tipo_requisito_asignatura VARCHAR NOT NULL

UNIQUE (asignatura_id, requisito_id, tipo_requisito_asignatura)
~~~

## programaciones_asignatura

**Contexto:** programación de una asignatura en período con fotografía del campus heredado.

~~~text
id UUID (PK)
periodo_academico_id UUID (FK → periodos_academicos.id) NOT NULL
asignatura_id UUID (FK → asignaturas.id) NOT NULL
campus_programacion VARCHAR NOT NULL CHECK (campus_programacion IN (
  'Campus Matriz Dr. Gabriel Galarza López',
  'Campus Laguacoto',
  'Campus CENI (Centro de Eventos Nacionales e Internacionales)',
  'Extensión San Miguel',
  'Sede Chimbo'
))
programacion_asignatura_activa BOOLEAN NOT NULL
modalidad_programacion_asignatura VARCHAR NOT NULL

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

**Contexto:** plantilla institucional que define el documento de sílabo.

~~~text
id UUID (PK)
documento_plantilla JSONB NOT NULL
mapeo_documento_plantilla JSONB NULL
revision_configuracion BIGINT NOT NULL DEFAULT 1

UNIQUE constante: existe solo una plantilla
CHECK: versión de esquema 1 y `secciones` es un arreglo
~~~

## definiciones_campo

**Contexto:** definición de cada campo del sílabo, sus reglas y configuración IA.

~~~text
id UUID (PK)
bloque_plantilla_id UUID NOT NULL
clave_definicion_campo VARCHAR NOT NULL
etiqueta_definicion_campo VARCHAR NOT NULL
ayuda_definicion_campo TEXT NULL
tipo_definicion_campo VARCHAR NOT NULL
heredado BOOLEAN NOT NULL
origen_maestro VARCHAR NULL
editable_docente BOOLEAN NOT NULL
ia_habilitada BOOLEAN NOT NULL
opciones_definicion_campo JSONB NULL
marcador_documento VARCHAR NULL
posicion_definicion_campo SMALLINT NOT NULL
plantilla_id UUID (FK → plantilla_silabo.id) NOT NULL

UNIQUE (plantilla_id, clave_definicion_campo)
UNIQUE (bloque_plantilla_id, posicion_definicion_campo)
TRIGGER: bloque_plantilla_id debe existir dentro de documento_plantilla
~~~

## fuentes_academicas

**Contexto:** documento interno de ingesta curricular de una carrera. I-146 retira su
CRUD/editor y su selección para revisión IA; conserva documentos y archivos privados
para importaciones I-145 y evidencia histórica. El nombre físico se mantiene.

~~~text
id UUID (PK)
carrera_id UUID (FK → carreras.id) NOT NULL
nombre_fuente_academica VARCHAR NOT NULL
descripcion_fuente_academica TEXT NULL
fuente_academica_activa BOOLEAN NOT NULL
contenido_fuente_academica TEXT NULL
documento_fuente_academica JSONB NOT NULL
version_documento_fuente SMALLINT NOT NULL DEFAULT 1
objeto_pdf_id UUID (FK → objetos_almacenados.id) NULL UNIQUE
estado_ingesta_fuente VARCHAR(24) NOT NULL DEFAULT 'manual'
ingesta_actualizada_en TIMESTAMPTZ NULL

UNIQUE (carrera_id, nombre_fuente_academica)

CHECK estado_ingesta_fuente IN ('manual', 'pendiente', 'en_ejecucion', 'completada', 'fallida')
CHECK documento_fuente_academica es un objeto doc con content como arreglo
CHECK documento_fuente_academica no contiene nodos de texto vacíos
~~~

## documentos_ia

**Contexto:** almacén vectorial histórico de I-135. I-146 desactiva indexación,
embeddings y recuperación; conserva esta estructura y sus registros, sin DDL ni
consulta desde el chat vigente.

~~~text
id UUID (PK)
contenido_documento TEXT NOT NULL
metadatos_documento JSONB NOT NULL DEFAULT '{}'
embedding VECTOR(1536) NULL

CHECK metadatos_documento es un objeto con fuente_academica_id
Metadatos: fuente_academica_id, carrera_id, titulo, ruta_encabezados, posicion,
  pagina_inicio, pagina_fin, bloques_origen, huella
Índice HNSW parcial de `embedding` con `vector_cosine_ops`
Índice btree ((metadatos_documento->>'fuente_academica_id'), posicion)
Índice GIN de búsqueda textual en español sobre título y contenido
Trigger eliminar_documentos_ia_de_fuente: borra los documentos de una fuente eliminada
~~~

### Cardinalidades

~~~text
plantilla_silabo 1:N definiciones_campo
carreras 1:N fuentes_academicas
fuentes_academicas 1:0..1 objetos_almacenados como PDF vigente
fuentes_academicas 1:N ejecuciones_trabajo por recurso lógico
fuentes_academicas 1:N documentos_ia mediante metadatos_documento.fuente_academica_id
documentos_ia 0..1 embedding vectorial por documento
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
proceso_id UUID (FK → convocatorias_universidad.id) NOT NULL

UNIQUE (carrera_id, proceso_id)
~~~

## silabos

**Contexto:** borrador vivo de un sílabo.

~~~text
id UUID (PK)
convocatoria_id UUID (FK → convocatorias_carreras.id) NOT NULL
paralelo_id UUID (FK → paralelos.id) NOT NULL
asignatura_id UUID (FK → asignaturas.id) NOT NULL
estado_silabo VARCHAR NOT NULL
version_bloqueo INTEGER NOT NULL
iniciado_en TIMESTAMPTZ NULL
guardado_en TIMESTAMPTZ NULL
contexto_academico_silabo JSONB NOT NULL
plantilla_id UUID (FK → plantilla_silabo.id) NOT NULL
~~~

`UNIQUE (convocatoria_id, paralelo_id)`. La carrera se obtiene de
`convocatorias_carreras.carrera_id` y la materia programada de
`paralelos.programacion_asignatura_id`; I-131 (`000099`) retiró `alcances_silabo` y
`silabos.carrera_id`.

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
heredado BOOLEAN NOT NULL
origen_valor_campo VARCHAR NULL

UNIQUE (silabo_id, definicion_campo_id)
~~~

## filas_repetibles (retirada)

I-156 la elimina (migración `000157`): lo que el docente escribía por filas se convirtió
en Texto libre (`valores_campo.valor_campo`, documento JSON) antes de borrarla.

## ejecuciones_validacion

**Contexto:** validación determinística de un borrador.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
ejecutado_por UUID (FK → usuarios.id) NOT NULL
version_reglas VARCHAR NOT NULL
estado_ejecucion_validacion VARCHAR NOT NULL
version_bloqueo INTEGER NOT NULL
errores_bloqueantes SMALLINT NOT NULL
cantidad_advertencias_validacion SMALLINT NOT NULL
completado_en TIMESTAMPTZ NOT NULL
~~~

## resultados_validacion

**Contexto:** detalle de un error o advertencia.

~~~text
id UUID (PK)
ejecucion_validacion_id UUID (FK → ejecuciones_validacion.id) NOT NULL
definicion_campo_id UUID (FK → definiciones_campo.id) NULL
codigo_resultado_validacion VARCHAR NOT NULL
severidad_resultado_validacion VARCHAR NOT NULL
mensaje_resultado_validacion VARCHAR NOT NULL
~~~

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
silabos 1:N ejecuciones_validacion
ejecuciones_validacion 1:N resultados_validacion
~~~

---

# 5. IA explicable

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

~~~text
id UUID (PK)
sesion_id TEXT NOT NULL
mensaje_ia JSONB NOT NULL
enviado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()

CHECK sesion_id ~ 'silabo:{uuid}:(campo:{uuid}|revision|usuario:{uuid})'
CHECK mensaje_ia objeto con entrada objeto,
  tipo IN ('analisis', 'revision', 'decision', 'mensaje', 'ocultamiento'),
  estado IN ('pendiente', 'en_ejecucion', 'completada', 'no_concluyente', 'parcial',
             'fallida', 'completado', 'fallido', 'registrada')
Índice btree (sesion_id text_pattern_ops, enviado_en)
UNIQUE parcial análisis: (sesion_id, entrada.clave_idempotencia, entrada.revision_id)
UNIQUE parcial revisión: (sesion_id, entrada.version_bloqueo_origen)
UNIQUE parcial pregunta de chat: (sesion_id, entrada.clave_idempotencia)
UNIQUE parcial decisión: (entrada.recomendacion_id, entrada.usuario_id, entrada.decision)
UNIQUE parcial aplicación: (entrada.recomendacion_id) WHERE decision = 'aplicada'
Trigger proteger_historial_ia: entrada inmutable; mensaje terminal inmutable; sin
  borrado salvo reinicio institucional (I-118)
Trigger eliminar_historial_ia_de_silabo (en silabos): borra las sesiones del sílabo
~~~

Forma de `mensaje_ia` por tipo:

~~~text
analisis   {tipo, estado, entrada{silabo_id, definicion_campo_id, clave_campo,
            revision_id, clave_idempotencia, clave_funcional, ejecucion_trabajo_id,
            version_contrato, version_instruccion, version_pasarela_solicitada, idioma,
            contenido, huella_contenido, huella_conjunto_fuentes,
            version_bloqueo_origen, parametros, solicitado_por, asignacion_rol_id,
            evidencia[{id, tipo, definicion_campo_id, fuente_academica_id,
                       documento_id, etiqueta, extracto, huella}]},
            recomendaciones[{id, ordinal, tipo, titulo, explicacion, texto_sugerido,
                             evidencia_ids}],
            version_pasarela_ejecutada, motivo_no_concluyente, codigo_error,
            mensaje_error, iniciado_en, completado_en}
revision   {tipo, estado, entrada{silabo_id, version_bloqueo_origen,
            clave_idempotencia, total_campos, solicitado_por, asignacion_rol_id},
            campos_finalizados, campos_con_recomendaciones, campos_fallidos,
            iniciado_en, completado_en}
decision   {tipo, estado: registrada, entrada{analisis_id, recomendacion_id,
            usuario_id, asignacion_rol_id, decision, contenido_antes?,
            contenido_despues?, version_bloqueo_origen?, version_bloqueo_resultado?}}
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
reapertura_id UUID (FK → reaperturas.id) NULL

UNIQUE (silabo_id, numero_revision)
UNIQUE (silabo_id, clave_idempotencia)
~~~

## observaciones_revision

**Contexto:** observaciones hechas sobre una revisión.

~~~text
id UUID (PK)
revision_silabo_id UUID (FK → revisiones_silabo.id) NOT NULL
clave_seccion VARCHAR NULL
clave_campo VARCHAR NULL
contenido_observacion_revision TEXT NOT NULL
estado_observacion_revision VARCHAR NOT NULL CHECK (abierta | verificada)
creado_por UUID (FK → usuarios.id) NOT NULL
observado_en TIMESTAMPTZ NOT NULL
~~~

## solicitudes_correccion

**Contexto:** solicitud formal de corrección.

~~~text
id UUID (PK)
revision_silabo_id UUID (FK → revisiones_silabo.id) NOT NULL UNIQUE
justificacion_solicitud_correccion TEXT NOT NULL
solicitado_por UUID (FK → usuarios.id) NOT NULL
solicitado_en TIMESTAMPTZ NOT NULL
~~~

## aprobaciones

**Contexto:** aprobación de una revisión.

~~~text
id UUID (PK)
revision_silabo_id UUID (FK → revisiones_silabo.id) NOT NULL UNIQUE
clave_idempotencia UUID NOT NULL
huella_sha256 CHAR(64) NOT NULL
aprobado_por UUID (FK → usuarios.id) NOT NULL
aprobado_en TIMESTAMPTZ NOT NULL
~~~

El sílabo se obtiene de `revisiones_silabo.silabo_id`. La clave de idempotencia sigue
siendo única por sílabo; la comprueba el trigger `validar_relaciones_flujo` (I-131).

## reaperturas

**Contexto:** reapertura posterior a una aprobación.

~~~text
id UUID (PK)
aprobacion_id UUID (FK → aprobaciones.id) NOT NULL UNIQUE
revision_aprobada_id UUID (FK → revisiones_silabo.id) NOT NULL INDEX
clave_idempotencia UUID NOT NULL
causa TEXT NOT NULL
reabierto_por UUID (FK → usuarios.id) NOT NULL
reabierto_en TIMESTAMPTZ NOT NULL
~~~

El sílabo se obtiene de `revision_aprobada_id`; el trigger exige que coincida con la
revisión de la aprobación y que la clave de idempotencia sea única por sílabo (I-131).

## Cambios de estado del sílabo

Desde I-136 no existe `transiciones_silabo`: cada cambio de estado queda en
`eventos_auditoria` (`silabo.enviado`, `silabo.reenviado`, `silabo.correccion_solicitada`,
`silabo.aprobado`, `silabo.reabierto`) con `from_state` y `to_state` en sus metadatos.

### Cardinalidades

~~~text
silabos 1:N revisiones_silabo
revisiones_silabo 0..1:N revisiones_silabo mediante revision_anterior_id
revisiones_silabo 1:N observaciones_revision
revisiones_silabo 1:0..1 solicitudes_correccion
solicitudes_correccion incluye todas las observaciones_revision de su revisión
  (derivado por revision_silabo_id; I-134)
revisiones_silabo 1:0..1 aprobaciones
aprobaciones 1:0..1 reaperturas
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
estado_objeto_almacenado VARCHAR NOT NULL
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
asignacion_rol_id UUID (FK → asignaciones_rol.id) NULL
solicitado_en TIMESTAMPTZ NOT NULL
completado_en TIMESTAMPTZ NULL
plantilla_id UUID (FK → plantilla_silabo.id) NOT NULL

UNIQUE (revision_silabo_id, clave_idempotencia)
INDEX (revision_silabo_id, estado_artefacto_exportacion, solicitado_en)
~~~

El sílabo se obtiene de la revisión exportada (I-131 retiró `silabo_id`).

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
resultado_evento_auditoria VARCHAR NOT NULL
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
revisiones_silabo 1:N artefactos_exportacion
ejecuciones_trabajo 1:0..1 artefactos_exportacion
objetos_almacenados 1:0..1 artefactos_exportacion como DOCX
objetos_almacenados 1:0..1 artefactos_exportacion como PDF
objetos_almacenados 1:0..1 facultades como logo
usuarios 1:N notificaciones_internas
usuarios 1:N eventos_auditoria
~~~

---
