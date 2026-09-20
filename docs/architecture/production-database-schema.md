# Base de datos actual de producción — Sílabos UEB

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

UNIQUE (asignacion_rol_id, paralelo_id)
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
paralelos 1:N asignaciones_paralelo
usuarios 1:N sesiones
~~~

---

# 2. Estructura académica

## facultades

**Contexto:** facultades institucionales.

~~~text
id UUID (PK)
codigo_facultad VARCHAR NULL UNIQUE
nombre_facultad VARCHAR NOT NULL
facultad_activa BOOLEAN NOT NULL
logo_objeto_id UUID (FK → objetos_almacenados.id) NULL UNIQUE
~~~

## campus

**Contexto:** sedes institucionales.

~~~text
id UUID (PK)
codigo_campus VARCHAR NULL UNIQUE
nombre_campus VARCHAR NOT NULL
campus_activo BOOLEAN NOT NULL
~~~

## carreras

**Contexto:** carreras académicas.

~~~text
id UUID (PK)
facultad_id UUID (FK → facultades.id) NOT NULL
codigo_carrera VARCHAR NULL UNIQUE
nombre_carrera VARCHAR NOT NULL
carrera_activa BOOLEAN NOT NULL
campus_id UUID (FK → campus.id) NULL
modalidad_carrera VARCHAR NULL
codigo_malla VARCHAR NOT NULL
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

**Contexto:** oferta de una asignatura en período y campus.

~~~text
id UUID (PK)
periodo_academico_id UUID (FK → periodos_academicos.id) NOT NULL
asignatura_id UUID (FK → asignaturas.id) NOT NULL
campus_id UUID (FK → campus.id) NOT NULL
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
campus 1:N carreras
carreras 1:N asignaturas
asignaturas 1:N requisitos_asignatura, como asignatura y como requisito
periodos_academicos 1:N programaciones_asignatura
asignaturas 1:N programaciones_asignatura
campus 1:N programaciones_asignatura
programaciones_asignatura 1:N paralelos
~~~

---

# 3. Plantilla institucional y fuentes

## plantillas_silabo

**Contexto:** plantilla institucional que define el documento de sílabo.

~~~text
id UUID (PK)
mapeo_documento_plantilla JSONB NULL
revision_configuracion BIGINT NOT NULL DEFAULT 1

UNIQUE constante: existe solo una plantilla
~~~

## componentes_plantilla

**Contexto:** secciones y bloques de la plantilla en una sola jerarquía.

~~~text
id UUID (PK)
plantilla_id UUID (FK → plantillas_silabo.id) NOT NULL
componente_padre_id UUID (FK → componentes_plantilla.id) NULL
clave_componente_plantilla VARCHAR NOT NULL
tipo_componente_plantilla VARCHAR NOT NULL
titulo_componente_plantilla VARCHAR NOT NULL
descripcion_componente_plantilla TEXT NULL
configuracion_componente_plantilla JSONB NULL
posicion_componente_plantilla SMALLINT NOT NULL

UNIQUE parcial (plantilla_id, clave_componente_plantilla) para raíces
UNIQUE parcial (plantilla_id, clave_componente_plantilla) para hijos
UNIQUE parcial (plantilla_id, posicion_componente_plantilla) para raíces
UNIQUE parcial (componente_padre_id, posicion_componente_plantilla) para hijos
~~~

## definiciones_campo

**Contexto:** definición de cada campo del sílabo, sus reglas y configuración IA.

~~~text
id UUID (PK)
componente_plantilla_id UUID (FK → componentes_plantilla.id) NOT NULL
clave_definicion_campo VARCHAR NOT NULL
etiqueta_definicion_campo VARCHAR NOT NULL
ayuda_definicion_campo TEXT NULL
tipo_definicion_campo VARCHAR NOT NULL
heredado BOOLEAN NOT NULL
origen_maestro VARCHAR NULL
editable_docente BOOLEAN NOT NULL
ia_habilitada BOOLEAN NOT NULL
ia_coordinacion_configurable BOOLEAN NOT NULL DEFAULT true
reglas_definicion_campo JSONB NULL
opciones_definicion_campo JSONB NULL
marcador_documento VARCHAR NULL
posicion_definicion_campo SMALLINT NOT NULL
plantilla_id UUID (FK → plantillas_silabo.id) NOT NULL

UNIQUE (plantilla_id, clave_definicion_campo)
UNIQUE (componente_plantilla_id, posicion_definicion_campo)
~~~

## fuentes_academicas

**Contexto:** fuentes de una carrera, almacenadas como Markdown y seleccionables para IA.
El Markdown puede provenir del editor o de una extracción PDF asíncrona con Mistral OCR.

~~~text
id UUID (PK)
carrera_id UUID (FK → carreras.id) NOT NULL
nombre_fuente_academica VARCHAR NOT NULL
descripcion_fuente_academica TEXT NULL
fuente_academica_activa BOOLEAN NOT NULL
contenido_fuente_academica TEXT NULL
objeto_pdf_id UUID (FK → objetos_almacenados.id) NULL UNIQUE
estado_ingesta_fuente VARCHAR(24) NOT NULL DEFAULT 'manual'
ingesta_actualizada_en TIMESTAMPTZ NULL

UNIQUE (carrera_id, nombre_fuente_academica)

CHECK estado_ingesta_fuente IN ('manual', 'pendiente', 'en_ejecucion', 'completada', 'fallida')
~~~

## extracciones_fuente_academica

**Contexto:** ejecución OCR de un PDF de fuente; conserva el resultado por página y el
vínculo con el trabajo asíncrono.

~~~text
id UUID (PK)
fuente_academica_id UUID (FK → fuentes_academicas.id) NOT NULL
objeto_pdf_id UUID (FK → objetos_almacenados.id) NOT NULL
ejecucion_trabajo_id UUID (FK → ejecuciones_trabajo.id) NULL UNIQUE
solicitada_por UUID (FK → usuarios.id) NOT NULL
estado_extraccion_fuente VARCHAR(24) NOT NULL DEFAULT 'pendiente'
modelo_ocr VARCHAR(100) NOT NULL
version_adaptador VARCHAR(80) NOT NULL
huella_pdf CHAR(64) NOT NULL
opciones_extraccion JSONB NULL
paginas_extraidas JSONB NULL
metadatos_respuesta JSONB NULL
huella_contenido CHAR(64) NULL
codigo_error VARCHAR(100) NULL
mensaje_error TEXT NULL
solicitada_en TIMESTAMPTZ NOT NULL
iniciada_en TIMESTAMPTZ NULL
completada_en TIMESTAMPTZ NULL
fallida_en TIMESTAMPTZ NULL

UNIQUE (fuente_academica_id, huella_pdf, modelo_ocr)
CHECK estado_extraccion_fuente IN ('pendiente', 'en_ejecucion', 'completada', 'fallida')
~~~

## preferencias_ia_carrera

**Contexto:** excepción de Coordinación sobre la configuración IA de un campo.

~~~text
id UUID (PK)
carrera_id UUID (FK → carreras.id) NOT NULL
definicion_campo_id UUID (FK → definiciones_campo.id) NOT NULL
ia_habilitada BOOLEAN NOT NULL
revision_plantilla_origen BIGINT NOT NULL
configurado_por UUID (FK → usuarios.id) NOT NULL
configurado_en TIMESTAMPTZ NOT NULL

UNIQUE (carrera_id, definicion_campo_id)
~~~

## fragmentos_fuente_academica

**Contexto:** índice derivado y reemplazable del contenido Markdown de una fuente.

~~~text
id UUID (PK)
fuente_academica_id UUID (FK → fuentes_academicas.id) NOT NULL
ruta_encabezados JSONB NOT NULL
titulo_fragmento VARCHAR NULL
posicion_fragmento SMALLINT NOT NULL
contenido_fragmento TEXT NOT NULL
huella_fragmento CHAR(64) NOT NULL
embedding VECTOR(1536) NULL

UNIQUE (fuente_academica_id, posicion_fragmento)
Índice GIN de búsqueda textual en español sobre título y contenido
Índice HNSW parcial de `embedding` con `vector_cosine_ops`
~~~

### Cardinalidades

~~~text
plantillas_silabo 1:N componentes_plantilla
componentes_plantilla 1:N componentes_plantilla
plantillas_silabo 1:N definiciones_campo
componentes_plantilla 1:N definiciones_campo
carreras 1:N fuentes_academicas
fuentes_academicas 1:0..1 objetos_almacenados como PDF vigente
fuentes_academicas 1:N extracciones_fuente_academica
extracciones_fuente_academica 1:0..1 ejecuciones_trabajo
objetos_almacenados 1:N extracciones_fuente_academica como PDF de entrada
fuentes_academicas 1:N fragmentos_fuente_academica
fragmentos_fuente_academica 0..1 embedding vectorial por fragmento
carreras 1:N preferencias_ia_carrera
definiciones_campo 1:N preferencias_ia_carrera
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
plantilla_id UUID (FK → plantillas_silabo.id) NOT NULL
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

## fuentes_convocatoria

**Contexto:** fuentes habilitadas en una convocatoria de carrera.

~~~text
id UUID (PK)
convocatoria_id UUID (FK → convocatorias_carreras.id) NOT NULL
fuente_academica_id UUID (FK → fuentes_academicas.id) NOT NULL

UNIQUE (convocatoria_id, fuente_academica_id)
~~~

## fechas_limite_convocatoria

**Contexto:** vencimiento por etapa.

~~~text
id UUID (PK)
convocatoria_id UUID (FK → convocatorias_carreras.id) NOT NULL
etapa_fecha_limite_convocatoria VARCHAR NOT NULL
vence_en TIMESTAMPTZ NOT NULL

UNIQUE (convocatoria_id, etapa_fecha_limite_convocatoria)
~~~

## silabos

**Contexto:** borrador vivo de un sílabo.

~~~text
id UUID (PK)
convocatoria_id UUID (FK → convocatorias_carreras.id) NOT NULL
asignatura_id UUID (FK → asignaturas.id) NOT NULL
carrera_id UUID (FK → carreras.id) NOT NULL
estado_silabo VARCHAR NOT NULL
version_bloqueo INTEGER NOT NULL
iniciado_en TIMESTAMPTZ NULL
guardado_en TIMESTAMPTZ NULL
contexto_academico_silabo JSONB NOT NULL
plantilla_id UUID (FK → plantillas_silabo.id) NOT NULL
~~~

## alcances_silabo

**Contexto:** paralelos cubiertos por un sílabo.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
convocatoria_id UUID (FK → convocatorias_carreras.id) NOT NULL
programacion_asignatura_id UUID (FK → programaciones_asignatura.id) NOT NULL
paralelo_id UUID (FK → paralelos.id) NOT NULL

UNIQUE (convocatoria_id, paralelo_id)
UNIQUE (silabo_id, paralelo_id)
~~~

## colaboradores_silabo

**Contexto:** docentes colaboradores de un sílabo.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
usuario_id UUID (FK → usuarios.id) NOT NULL
docente_paralelo_id UUID (FK → asignaciones_paralelo.id) NOT NULL

UNIQUE (silabo_id, docente_paralelo_id)
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

## filas_repetibles

**Contexto:** filas ordenadas de campos tipo tabla o repetibles.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
definicion_campo_id UUID (FK → definiciones_campo.id) NOT NULL
datos_fila_repetible JSONB NOT NULL
posicion_fila_repetible SMALLINT NOT NULL

UNIQUE (silabo_id, definicion_campo_id, posicion_fila_repetible)
~~~

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
plantillas_silabo 1:N convocatorias_universidad
convocatorias_universidad 1:N convocatorias_carreras
carreras 1:N convocatorias_carreras
convocatorias_carreras N:M fuentes_academicas mediante fuentes_convocatoria
convocatorias_carreras 1:N fechas_limite_convocatoria
convocatorias_carreras 1:N silabos
silabos N:M paralelos mediante alcances_silabo
silabos N:M usuarios mediante colaboradores_silabo
silabos N:M definiciones_campo mediante valores_campo
silabos 1:N filas_repetibles
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

## ejecuciones_ia

**Contexto:** una solicitud de IA sobre un campo de un sílabo.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
definicion_campo_id UUID (FK → definiciones_campo.id) NOT NULL
ejecucion_trabajo_id UUID (FK → ejecuciones_trabajo.id) NULL UNIQUE
clave_idempotencia UUID NOT NULL
clave_funcional CHAR(64) NOT NULL
estado_ejecucion_ia VARCHAR NOT NULL
version_contrato VARCHAR NOT NULL
version_instruccion VARCHAR NOT NULL
version_pasarela_solicitada VARCHAR NOT NULL
version_pasarela_ejecutada VARCHAR NULL
idioma VARCHAR NOT NULL
contenido_entrada_ia TEXT NOT NULL
huella_contenido CHAR(64) NOT NULL
huella_conjunto_fuentes CHAR(64) NOT NULL
metadatos_entrada_ia JSONB NOT NULL
version_bloqueo_origen INTEGER NOT NULL
motivo_no_concluyente VARCHAR NULL
codigo_error VARCHAR NULL
mensaje_error TEXT NULL
solicitado_por UUID (FK → usuarios.id) NOT NULL
asignacion_rol_id UUID (FK → asignaciones_rol.id) NULL
solicitado_en TIMESTAMPTZ NOT NULL
iniciado_en TIMESTAMPTZ NULL
completado_en TIMESTAMPTZ NULL
plantilla_id UUID (FK → plantillas_silabo.id) NOT NULL
revision_ia_silabo_id UUID (FK → revisiones_ia_silabo.id) NULL

UNIQUE (silabo_id, definicion_campo_id, clave_idempotencia)
UNIQUE parcial sobre clave_funcional
UNIQUE (revision_ia_silabo_id, definicion_campo_id)
~~~

## revisiones_ia_silabo

**Contexto:** ejecución padre de una revisión integral de IA sobre una versión del sílabo.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
version_bloqueo_origen INTEGER NOT NULL
clave_idempotencia UUID NOT NULL
estado_revision_ia VARCHAR NOT NULL DEFAULT 'pendiente'
total_campos SMALLINT NOT NULL
campos_finalizados SMALLINT NOT NULL DEFAULT 0
campos_con_recomendaciones SMALLINT NOT NULL DEFAULT 0
campos_fallidos SMALLINT NOT NULL DEFAULT 0
solicitado_por UUID (FK → usuarios.id) NOT NULL
asignacion_rol_id UUID (FK → asignaciones_rol.id) NULL
solicitado_en TIMESTAMPTZ NOT NULL
iniciado_en TIMESTAMPTZ NULL
completado_en TIMESTAMPTZ NULL

UNIQUE (silabo_id, version_bloqueo_origen)
UNIQUE (silabo_id, clave_idempotencia)
~~~

## evidencias_ia

**Contexto:** extractos de fuente que respaldan una ejecución IA.

~~~text
id UUID (PK)
ejecucion_ia_id UUID (FK → ejecuciones_ia.id) NOT NULL
fuente_academica_id UUID (FK → fuentes_academicas.id) NULL
etiqueta_evidencia_ia VARCHAR NOT NULL
extracto_evidencia_ia TEXT NOT NULL
huella_contenido CHAR(64) NOT NULL
tipo_evidencia_ia VARCHAR NOT NULL DEFAULT 'fuente_academica'
definicion_campo_id UUID (FK → definiciones_campo.id) NULL
fragmento_fuente_academica_id UUID (FK → fragmentos_fuente_academica.id) NULL

CHECK (tipo_evidencia_ia IN ('fuente_academica', 'seccion_silabo'))
CHECK: fuente académica referencia fuente y opcionalmente fragmento; sección de sílabo
referencia definición de campo sin fuente ni fragmento
~~~

## recomendaciones_ia

**Contexto:** recomendaciones generadas para un campo.

~~~text
id UUID (PK)
ejecucion_ia_id UUID (FK → ejecuciones_ia.id) NOT NULL
definicion_campo_id UUID (FK → definiciones_campo.id) NOT NULL
ordinal SMALLINT NOT NULL
tipo_recomendacion_ia VARCHAR NOT NULL
titulo_recomendacion_ia VARCHAR NOT NULL
explicacion_recomendacion_ia TEXT NOT NULL
texto_sugerido TEXT NOT NULL

UNIQUE (ejecucion_ia_id, ordinal)
~~~

## recomendacion_evidencias_ia

**Contexto:** evidencia usada por cada recomendación.

~~~text
id UUID (PK)
recomendacion_ia_id UUID (FK → recomendaciones_ia.id) NOT NULL
evidencia_ia_id UUID (FK → evidencias_ia.id) NOT NULL

UNIQUE (recomendacion_ia_id, evidencia_ia_id)
~~~

## retroalimentacion_ia

**Contexto:** decisión humana respecto de una recomendación IA.

~~~text
id UUID (PK)
recomendacion_ia_id UUID (FK → recomendaciones_ia.id) NOT NULL
usuario_id UUID (FK → usuarios.id) NOT NULL
asignacion_rol_id UUID (FK → asignaciones_rol.id) NULL
decision_retroalimentacion_ia VARCHAR NOT NULL
contenido_anterior_retroalimentacion_ia TEXT NULL
contenido_posterior_retroalimentacion_ia TEXT NULL
version_bloqueo_origen INTEGER NULL
version_bloqueo_resultado INTEGER NULL
decidido_en TIMESTAMPTZ NOT NULL

UNIQUE (recomendacion_ia_id, usuario_id, decision_retroalimentacion_ia)
UNIQUE parcial: una sola decisión aplicada por recomendación
~~~

## conversaciones_ia

**Contexto:** conversación contextual de un docente sobre un sílabo.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
usuario_id UUID (FK → usuarios.id) NOT NULL
ocultada_en TIMESTAMPTZ NULL
iniciada_en TIMESTAMPTZ NOT NULL

UNIQUE (silabo_id, usuario_id)
~~~

## mensajes_ia

**Contexto:** pregunta o respuesta persistida dentro de una conversación IA.

~~~text
id UUID (PK)
conversacion_ia_id UUID (FK → conversaciones_ia.id) NOT NULL
respuesta_a_id UUID (FK → mensajes_ia.id) NULL
ejecucion_trabajo_id UUID (FK → ejecuciones_trabajo.id) NULL UNIQUE
clave_idempotencia UUID NULL
rol_mensaje_ia VARCHAR NOT NULL
estado_mensaje_ia VARCHAR NOT NULL
contenido_mensaje_ia TEXT NOT NULL
version_bloqueo_origen INTEGER NOT NULL
version_pasarela_ejecutada VARCHAR NULL
codigo_error VARCHAR NULL
mensaje_error TEXT NULL
completado_en TIMESTAMPTZ NULL
enviado_en TIMESTAMPTZ NOT NULL

CHECK (rol_mensaje_ia IN ('usuario', 'asistente'))
CHECK (estado_mensaje_ia IN ('completado', 'pendiente', 'en_ejecucion', 'fallido'))
UNIQUE (conversacion_ia_id, clave_idempotencia)
~~~

## evidencias_mensaje_ia

**Contexto:** fotografía de la evidencia usada por una respuesta del chat.

~~~text
id UUID (PK)
mensaje_ia_id UUID (FK → mensajes_ia.id) NOT NULL
tipo_evidencia_mensaje_ia VARCHAR NOT NULL
definicion_campo_id UUID (FK → definiciones_campo.id) NULL
fuente_academica_id UUID (FK → fuentes_academicas.id) NULL
etiqueta_evidencia_mensaje_ia VARCHAR NOT NULL
extracto_evidencia_mensaje_ia TEXT NOT NULL
huella_evidencia_mensaje_ia CHAR(64) NOT NULL
fijada_en TIMESTAMPTZ NOT NULL

CHECK (tipo_evidencia_mensaje_ia IN ('seccion_silabo', 'fuente_academica'))
CHECK: sección referencia definición de campo; fuente referencia fuente académica
UNIQUE (mensaje_ia_id, huella_evidencia_mensaje_ia)
~~~

## citas_mensaje_ia

**Contexto:** evidencias citadas por una respuesta del chat, en orden de presentación.

~~~text
mensaje_ia_id UUID (FK → mensajes_ia.id) NOT NULL
evidencia_mensaje_ia_id UUID (FK → evidencias_mensaje_ia.id) NOT NULL
ordinal_cita_mensaje_ia SMALLINT NOT NULL

PRIMARY KEY (mensaje_ia_id, evidencia_mensaje_ia_id)
UNIQUE (mensaje_ia_id, ordinal_cita_mensaje_ia)
~~~

### Cardinalidades

~~~text
silabos 1:N ejecuciones_ia
definiciones_campo 1:N ejecuciones_ia
ejecuciones_trabajo 1:0..1 ejecuciones_ia
ejecuciones_ia 1:N evidencias_ia
fuentes_academicas 1:N evidencias_ia
ejecuciones_ia 1:N recomendaciones_ia
recomendaciones_ia N:M evidencias_ia mediante recomendacion_evidencias_ia
recomendaciones_ia 1:N retroalimentacion_ia
revisiones_ia_silabo 1:N ejecuciones_ia
silabos 1:N revisiones_ia_silabo
usuarios 1:N revisiones_ia_silabo
asignaciones_rol 1:N revisiones_ia_silabo
fragmentos_fuente_academica 1:N evidencias_ia
silabos 1:N conversaciones_ia
usuarios 1:N conversaciones_ia
conversaciones_ia 1:N mensajes_ia
mensajes_ia 1:N mensajes_ia mediante respuesta_a_id
mensajes_ia 1:N evidencias_mensaje_ia
mensajes_ia N:M evidencias_mensaje_ia mediante citas_mensaje_ia
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
estado_observacion_revision VARCHAR NOT NULL
creado_por UUID (FK → usuarios.id) NOT NULL
observado_en TIMESTAMPTZ NOT NULL
~~~

## solicitudes_correccion

**Contexto:** solicitud formal de corrección.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
revision_silabo_id UUID (FK → revisiones_silabo.id) NOT NULL UNIQUE
justificacion_solicitud_correccion TEXT NOT NULL
solicitado_por UUID (FK → usuarios.id) NOT NULL
solicitado_en TIMESTAMPTZ NOT NULL
~~~

## solicitud_correccion_observaciones

**Contexto:** puente entre solicitudes y observaciones.

~~~text
id UUID (PK)
solicitud_correccion_id UUID (FK → solicitudes_correccion.id) NOT NULL
observacion_revision_id UUID (FK → observaciones_revision.id) NOT NULL

UNIQUE (solicitud_correccion_id, observacion_revision_id)
~~~

## respuestas_observacion

**Contexto:** respuesta del docente a una observación.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
observacion_revision_id UUID (FK → observaciones_revision.id) NOT NULL UNIQUE
revision_respuesta_id UUID (FK → revisiones_silabo.id) NULL
contenido_respuesta_observacion TEXT NOT NULL
respondido_por UUID (FK → usuarios.id) NOT NULL
respondido_en TIMESTAMPTZ NOT NULL
~~~

## aprobaciones

**Contexto:** aprobación de una revisión.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
revision_silabo_id UUID (FK → revisiones_silabo.id) NOT NULL UNIQUE
clave_idempotencia UUID NOT NULL
huella_sha256 CHAR(64) NOT NULL
aprobado_por UUID (FK → usuarios.id) NOT NULL
aprobado_en TIMESTAMPTZ NOT NULL

UNIQUE (silabo_id, clave_idempotencia)
~~~

## reaperturas

**Contexto:** reapertura posterior a una aprobación.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
aprobacion_id UUID (FK → aprobaciones.id) NOT NULL UNIQUE
revision_aprobada_id UUID (FK → revisiones_silabo.id) NOT NULL
clave_idempotencia UUID NOT NULL
causa TEXT NOT NULL
reabierto_por UUID (FK → usuarios.id) NOT NULL
reabierto_en TIMESTAMPTZ NOT NULL

UNIQUE (silabo_id, clave_idempotencia)
~~~

## transiciones_silabo

**Contexto:** bitácora de cambios de estado.

~~~text
id UUID (PK)
silabo_id UUID (FK → silabos.id) NOT NULL
revision_silabo_id UUID (FK → revisiones_silabo.id) NULL
estado_origen VARCHAR NOT NULL
estado_destino VARCHAR NOT NULL
accion_transicion_silabo VARCHAR NOT NULL
actor_usuario_id UUID (FK → usuarios.id) NOT NULL
asignacion_rol_id UUID (FK → asignaciones_rol.id) NULL
metadatos_transicion_silabo JSONB NULL
ocurrido_en TIMESTAMPTZ NOT NULL
~~~

### Cardinalidades

~~~text
silabos 1:N revisiones_silabo
revisiones_silabo 0..1:N revisiones_silabo mediante revision_anterior_id
revisiones_silabo 1:N observaciones_revision
revisiones_silabo 1:0..1 solicitudes_correccion
solicitudes_correccion N:M observaciones_revision
  mediante solicitud_correccion_observaciones
observaciones_revision 1:0..1 respuestas_observacion
revisiones_silabo 1:0..1 aprobaciones
aprobaciones 1:0..1 reaperturas
silabos 1:N transiciones_silabo
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
silabo_id UUID (FK → silabos.id) NOT NULL
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
plantilla_id UUID (FK → plantillas_silabo.id) NOT NULL

UNIQUE (revision_silabo_id, clave_idempotencia)
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
silabos 1:N artefactos_exportacion
revisiones_silabo 1:N artefactos_exportacion
ejecuciones_trabajo 1:0..1 artefactos_exportacion
objetos_almacenados 1:0..1 artefactos_exportacion como DOCX
objetos_almacenados 1:0..1 artefactos_exportacion como PDF
objetos_almacenados 1:0..1 facultades como logo
usuarios 1:N notificaciones_internas
usuarios 1:N eventos_auditoria
~~~

---
