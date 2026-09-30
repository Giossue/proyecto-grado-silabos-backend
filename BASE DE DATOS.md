# Modelo entidad-relación de la base de datos

Actualizado el 2026-09-30: **42 tablas** y 70 relaciones.

**Llave:** `PK` = identificador; `FK → tabla` = apunta a otra tabla. **¿Vacío?:** si puede quedar sin valor.

## Identidad y operación

### usuarios

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `nombre_usuario` | varchar | no |  |
| `correo_electronico` | varchar | no |  |
| `correo_verificado_en` | timestamptz | sí |  |
| `contrasena` | varchar | no |  |
| `usuario_activo` | boolean | no |  |
| `codigo_recordarme` | varchar | sí |  |
| `secreto_dos_factores` | text | sí |  |
| `codigos_recuperacion_dos_factores` | text | sí |  |
| `dos_factores_confirmado_en` | timestamptz | sí |  |

### asignaciones_rol

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `usuario_id` | uuid | no | FK → `usuarios` |
| `carrera_id` | uuid | sí | FK → `carreras` |
| `asignacion_rol_activa` | boolean | no |  |
| `asignado_en` | timestamptz | no |  |
| `rol` | varchar | no |  |

### invitaciones_activacion

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `usuario_id` | uuid | no | FK → `usuarios` |
| `hash_activacion` | char | no |  |
| `expira_en` | timestamptz | no |  |
| `usada_en` | timestamptz | sí |  |
| `invalidada_en` | timestamptz | sí |  |
| `emitida_por_usuario_id` | uuid | sí | FK → `usuarios` |
| `creada_en` | timestamptz | no |  |

### restablecimientos_contrasena

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `email` | varchar | no |  |
| `token` | varchar | no |  |
| `created_at` | timestamptz | sí |  |

### sesiones

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | varchar | no | PK |
| `user_id` | uuid | sí | FK → `usuarios` |
| `ip_address` | varchar | sí |  |
| `user_agent` | text | sí |  |
| `payload` | text | no |  |
| `last_activity` | integer | no |  |

### eventos_auditoria

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `usuario_id` | uuid | sí | FK → `usuarios` |
| `asignacion_rol_id` | uuid | sí | FK → `asignaciones_rol` |
| `accion` | varchar | no |  |
| `tipo_recurso` | varchar | no |  |
| `recurso_id` | uuid | sí |  |
| `resultado` | varchar | no |  |
| `detalles` | jsonb | sí |  |
| `ocurrido_en` | timestamptz | no |  |

### notificaciones

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `usuario_id` | uuid | no | FK → `usuarios` |
| `clave_unica` | varchar | no |  |
| `tipo_notificacion` | varchar | no |  |
| `titulo_notificacion` | varchar | no |  |
| `mensaje_notificacion` | text | no |  |
| `tipo_recurso` | varchar | sí |  |
| `recurso_id` | uuid | sí |  |
| `leido_en` | timestamptz | sí |  |
| `notificado_en` | timestamptz | no |  |

### trabajos_en_cola

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `tipo_trabajo` | varchar | no |  |
| `estado_trabajo` | varchar | no |  |
| `clave_solicitud` | varchar | no |  |
| `intentos` | smallint | no |  |
| `progreso` | smallint | no |  |
| `resultado_trabajo` | jsonb | sí |  |
| `codigo_error` | varchar | sí |  |
| `mensaje_error` | text | sí |  |
| `iniciado_en` | timestamptz | sí |  |
| `finalizado_en` | timestamptz | sí |  |
| `encolado_en` | timestamptz | sí |  |
| `cola` | varchar | no |  |
| `tipo_recurso` | varchar | sí |  |
| `recurso_id` | uuid | sí |  |
| `intentos_maximos` | smallint | no |  |
| `datos_entrada` | jsonb | sí |  |

### trabajos_fallidos

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | bigint | no | PK |
| `uuid` | varchar | no |  |
| `connection` | varchar | no |  |
| `queue` | varchar | no |  |
| `payload` | text | no |  |
| `exception` | text | no |  |
| `failed_at` | timestamp_without_time_zone | no |  |

### archivos

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `disco` | varchar | no |  |
| `ruta_archivo` | varchar | no |  |
| `nombre_archivo` | varchar | no |  |
| `tipo_archivo` | varchar | no |  |
| `tamano_bytes` | bigint | no |  |
| `hash_archivo` | char | no |  |
| `categoria` | varchar | no |  |
| `subido_por` | uuid | sí | FK → `usuarios` |
| `carrera_id` | uuid | sí | FK → `carreras` |
| `almacenado_en` | timestamptz | no |  |

### migraciones

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | integer | no | PK |
| `migration` | varchar | no |  |
| `batch` | integer | no |  |

### Relaciones: Identidad y operación

- archivos.carrera_id → carreras
- archivos.subido_por → usuarios
- asignaciones_rol.carrera_id → carreras
- asignaciones_rol.usuario_id → usuarios
- eventos_auditoria.asignacion_rol_id → asignaciones_rol
- eventos_auditoria.usuario_id → usuarios
- invitaciones_activacion.emitida_por_usuario_id → usuarios
- invitaciones_activacion.usuario_id → usuarios
- notificaciones.usuario_id → usuarios
- sesiones.user_id → usuarios

## Estructura académica

### facultades

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `nombre_facultad` | varchar | no |  |
| `facultad_activa` | boolean | no |  |
| `logo_archivo_id` | uuid | sí | FK → `archivos` |
| `campus_facultad` | varchar | no |  |

### carreras

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `facultad_id` | uuid | no | FK → `facultades` |
| `codigo_carrera` | varchar | no |  |
| `nombre_carrera` | varchar | no |  |
| `carrera_activa` | boolean | no |  |
| `modalidad_carrera` | enum modalidad_estudio | sí |  |
| `numero_ciclos` | smallint | no |  |

### asignaturas

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `codigo_asignatura` | varchar | no |  |
| `nombre_asignatura` | varchar | no |  |
| `ciclo_asignatura` | smallint | sí |  |
| `creditos_asignatura` | numeric | sí |  |
| `asignatura_activa` | boolean | no |  |
| `horas_docencia` | numeric | sí |  |
| `horas_practicas` | numeric | sí |  |
| `horas_autonomas` | numeric | sí |  |
| `orden_asignatura_en_ciclo` | smallint | no |  |
| `unidad_curricular_asignatura` | varchar | sí |  |
| `modalidad_asignatura` | enum modalidad_estudio | sí |  |
| `carrera_id` | uuid | no | FK → `carreras` |
| `total_horas_asignatura` | smallint | sí |  |

### requisitos_asignatura

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `asignatura_id` | uuid | no | FK → `asignaturas` |
| `requisito_id` | uuid | no | FK → `asignaturas` |
| `tipo_requisito_asignatura` | varchar | no |  |

### periodos_academicos

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `codigo_periodo_academico` | varchar | no |  |
| `fecha_inicio_periodo` | date | no |  |
| `fecha_fin_periodo` | date | no |  |
| `cantidad_semanas_lectivas` | smallint | no |  |

### asignaturas_ofertadas

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `periodo_academico_id` | uuid | no | FK → `periodos_academicos` |
| `asignatura_id` | uuid | no | FK → `asignaturas` |
| `asignatura_ofertada_activa` | boolean | no |  |

### paralelos

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `asignatura_ofertada_id` | uuid | no | FK → `asignaturas_ofertadas` |
| `codigo_paralelo` | varchar | no |  |
| `jornada_paralelo` | varchar | no |  |

### asignaciones_paralelo

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `paralelo_id` | uuid | no | FK → `paralelos` |
| `asignacion_paralelo_activa` | boolean | no |  |
| `asignado_en` | timestamptz | no |  |
| `asignacion_rol_id` | uuid | no | FK → `asignaciones_rol` |

### conceptos_curriculares

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `clave_concepto` | varchar | no |  |
| `etiqueta_concepto` | varchar | no |  |
| `tipo_concepto` | varchar | no |  |
| `columnas_concepto` | jsonb | sí |  |

### valores_curriculares

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `concepto_id` | uuid | no | FK → `conceptos_curriculares` |
| `carrera_id` | uuid | no |  |
| `asignatura_id` | uuid | no | FK → `asignaturas` |
| `valor_curricular` | jsonb | no |  |
| `evidencia_curricular` | jsonb | no |  |
| `confirmado_por` | uuid | no | FK → `usuarios` |
| `confirmado_en` | timestamptz | no |  |

### importaciones_curriculares

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `carrera_id` | uuid | no | FK → `carreras` |
| `solicitado_por` | uuid | no | FK → `usuarios` |
| `estado_importacion` | varchar | no |  |
| `clave_solicitud` | char | sí |  |
| `documentos_importacion` | jsonb | no |  |
| `propuesta_importacion` | jsonb | sí |  |
| `seleccion_aplicada` | jsonb | sí |  |
| `instrucciones_importacion` | text | sí |  |
| `mensaje_error_importacion` | text | sí |  |
| `solicitado_en` | timestamptz | no |  |
| `aplicado_en` | timestamptz | sí |  |

### documentos_importacion

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `carrera_id` | uuid | no | FK → `carreras` |
| `nombre_documento` | varchar | no |  |
| `documento_activo` | boolean | no |  |
| `archivo_pdf_id` | uuid | sí | FK → `archivos` |
| `estado_lectura` | varchar | no |  |
| `lectura_actualizada_en` | timestamptz | sí |  |
| `contenido_documento` | jsonb | no |  |

### Relaciones: Estructura académica

- asignaciones_paralelo.asignacion_rol_id → asignaciones_rol
- asignaciones_paralelo.paralelo_id → paralelos
- asignaturas.carrera_id → carreras
- asignaturas_ofertadas.asignatura_id → asignaturas
- asignaturas_ofertadas.periodo_academico_id → periodos_academicos
- carreras.facultad_id → facultades
- documentos_importacion.archivo_pdf_id → archivos
- documentos_importacion.carrera_id → carreras
- facultades.logo_archivo_id → archivos
- importaciones_curriculares.carrera_id → carreras
- importaciones_curriculares.solicitado_por → usuarios
- paralelos.asignatura_ofertada_id → asignaturas_ofertadas
- requisitos_asignatura.asignatura_id → asignaturas
- requisitos_asignatura.requisito_id → asignaturas
- valores_curriculares.asignatura_id → asignaturas
- valores_curriculares.concepto_id → conceptos_curriculares
- valores_curriculares.confirmado_por → usuarios

## Plantilla del sílabo

### plantilla_silabo

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `mapeo_documento_plantilla` | jsonb | sí |  |

### secciones_plantilla

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `plantilla_id` | uuid | no | FK → `plantilla_silabo` |
| `clave_seccion_plantilla` | varchar | no |  |
| `titulo_seccion_plantilla` | varchar | no |  |
| `descripcion_seccion_plantilla` | text | sí |  |
| `posicion_seccion_plantilla` | smallint | no |  |

### campos_plantilla

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `seccion_plantilla_id` | uuid | no | FK → `secciones_plantilla` |
| `plantilla_id` | uuid | no | FK → `plantilla_silabo` |
| `clave_campo_plantilla` | varchar | no |  |
| `titulo_campo_plantilla` | varchar | no |  |
| `posicion_campo_plantilla` | smallint | no |  |
| `configuracion_campo_plantilla` | jsonb | no |  |

### definiciones_campo

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `campo_plantilla_id` | uuid | no | FK → `campos_plantilla` |
| `clave_definicion_campo` | varchar | no |  |
| `etiqueta_definicion_campo` | varchar | no |  |
| `ayuda_definicion_campo` | text | sí |  |
| `tipo_definicion_campo` | varchar | no |  |
| `heredado_definicion_campo` | boolean | no |  |
| `origen_dato_definicion_campo` | varchar | sí |  |
| `editable_docente_definicion_campo` | boolean | no |  |
| `opciones_definicion_campo` | jsonb | sí |  |
| `posicion_definicion_campo` | smallint | no |  |
| `plantilla_id` | uuid | no | FK → `plantilla_silabo` |
| `condicion_definicion_campo` | jsonb | sí |  |

### Relaciones: Plantilla del sílabo

- campos_plantilla.plantilla_id → plantilla_silabo
- campos_plantilla.seccion_plantilla_id → secciones_plantilla
- definiciones_campo.campo_plantilla_id → campos_plantilla
- definiciones_campo.plantilla_id → plantilla_silabo
- secciones_plantilla.plantilla_id → plantilla_silabo

## Convocatorias, sílabos y revisión

### convocatorias_universidad

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `fecha_inicio` | timestamptz | no |  |
| `fecha_entrega` | timestamptz | no |  |
| `estado_convocatoria_universidad` | varchar | no |  |
| `periodo_academico_id` | uuid | no | FK → `periodos_academicos` |

### convocatorias_carreras

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `carrera_id` | uuid | no | FK → `carreras` |
| `estado_convocatoria_carrera` | varchar | no |  |
| `convocatoria_universidad_id` | uuid | no | FK → `convocatorias_universidad` |

### silabos

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `convocatoria_carrera_id` | uuid | no | FK → `convocatorias_carreras` |
| `estado_silabo` | varchar | no |  |
| `version_borrador` | integer | no |  |
| `iniciado_en` | timestamptz | sí |  |
| `guardado_en` | timestamptz | sí |  |
| `datos_academicos_silabo` | jsonb | no |  |
| `paralelo_id` | uuid | no | FK → `paralelos` |

### valores_campo

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `silabo_id` | uuid | no | FK → `silabos` |
| `definicion_campo_id` | uuid | no | FK → `definiciones_campo` |
| `valor_campo` | jsonb | sí |  |

### revisiones_silabo

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `silabo_id` | uuid | no | FK → `silabos` |
| `revision_anterior_id` | uuid | sí | FK → `revisiones_silabo` |
| `numero_revision` | smallint | no |  |
| `clave_solicitud` | uuid | no |  |
| `version_borrador` | integer | no |  |
| `contenido_enviado` | jsonb | no |  |
| `hash_contenido` | char | no |  |
| `enviado_por` | uuid | no | FK → `usuarios` |
| `enviado_en` | timestamptz | no |  |
| `reapertura_id` | uuid | sí | FK → `reaperturas` |

### observaciones_revision

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `revision_silabo_id` | uuid | no | FK → `revisiones_silabo` |
| `clave_seccion` | varchar | sí |  |
| `clave_campo` | varchar | sí |  |
| `contenido_observacion_revision` | text | no |  |
| `estado_observacion_revision` | varchar | no |  |
| `creado_por` | uuid | no | FK → `usuarios` |
| `observado_en` | timestamptz | no |  |

### solicitudes_correccion

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `revision_silabo_id` | uuid | no | FK → `revisiones_silabo` |
| `justificacion_solicitud_correccion` | text | no |  |
| `solicitado_por` | uuid | no | FK → `usuarios` |
| `solicitado_en` | timestamptz | no |  |

### aprobaciones

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `revision_silabo_id` | uuid | no | FK → `revisiones_silabo` |
| `clave_solicitud` | uuid | no |  |
| `hash_contenido` | char | no |  |
| `aprobado_por` | uuid | no | FK → `usuarios` |
| `aprobado_en` | timestamptz | no |  |
| `aprobacion_anterior_id` | uuid | sí | FK → `aprobaciones` |

### reaperturas

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `aprobacion_id` | uuid | no | FK → `aprobaciones` |
| `revision_aprobada_id` | uuid | no | FK → `revisiones_silabo` |
| `clave_solicitud` | uuid | no |  |
| `causa` | text | no |  |
| `reabierto_por` | uuid | no | FK → `usuarios` |
| `reabierto_en` | timestamptz | no |  |
| `origen_reapertura` | varchar | no |  |

### validaciones_silabo

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `silabo_id` | uuid | no | FK → `silabos` |
| `ejecutado_por` | uuid | no | FK → `usuarios` |
| `version_reglas` | varchar | no |  |
| `estado_validacion` | varchar | no |  |
| `version_borrador` | integer | no |  |
| `cantidad_errores_validacion` | smallint | no |  |
| `cantidad_advertencias_validacion` | smallint | no |  |
| `completado_en` | timestamptz | no |  |

### resultados_validacion

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `validacion_silabo_id` | uuid | no | FK → `validaciones_silabo` |
| `definicion_campo_id` | uuid | sí | FK → `definiciones_campo` |
| `codigo_resultado_validacion` | varchar | no |  |
| `severidad_resultado_validacion` | varchar | no |  |
| `mensaje_resultado_validacion` | varchar | no |  |

### envios_biblioteca

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `revision_silabo_id` | uuid | no | FK → `revisiones_silabo` |
| `enviado_por` | uuid | no | FK → `usuarios` |
| `enviado_en` | timestamptz | no |  |
| `verificado_por` | uuid | sí | FK → `usuarios` |
| `verificado_en` | timestamptz | sí |  |

### mensajes_ia

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `contenido_mensaje` | jsonb | no |  |
| `enviado_en` | timestamptz | no |  |
| `silabo_id` | uuid | no | FK → `silabos` |
| `usuario_id` | uuid | no | FK → `usuarios` |

### Relaciones: Convocatorias, sílabos y revisión

- aprobaciones.aprobacion_anterior_id → aprobaciones
- aprobaciones.aprobado_por → usuarios
- aprobaciones.revision_silabo_id → revisiones_silabo
- convocatorias_carreras.carrera_id → carreras
- convocatorias_carreras.convocatoria_universidad_id → convocatorias_universidad
- convocatorias_universidad.periodo_academico_id → periodos_academicos
- envios_biblioteca.enviado_por → usuarios
- envios_biblioteca.revision_silabo_id → revisiones_silabo
- envios_biblioteca.verificado_por → usuarios
- mensajes_ia.silabo_id → silabos
- mensajes_ia.usuario_id → usuarios
- observaciones_revision.creado_por → usuarios
- observaciones_revision.revision_silabo_id → revisiones_silabo
- reaperturas.aprobacion_id → aprobaciones
- reaperturas.reabierto_por → usuarios
- reaperturas.revision_aprobada_id → revisiones_silabo
- resultados_validacion.definicion_campo_id → definiciones_campo
- resultados_validacion.validacion_silabo_id → validaciones_silabo
- revisiones_silabo.enviado_por → usuarios
- revisiones_silabo.reapertura_id → reaperturas
- revisiones_silabo.revision_anterior_id → revisiones_silabo
- revisiones_silabo.silabo_id → silabos
- silabos.convocatoria_carrera_id → convocatorias_carreras
- silabos.paralelo_id → paralelos
- solicitudes_correccion.revision_silabo_id → revisiones_silabo
- solicitudes_correccion.solicitado_por → usuarios
- validaciones_silabo.ejecutado_por → usuarios
- validaciones_silabo.silabo_id → silabos
- valores_campo.definicion_campo_id → definiciones_campo
- valores_campo.silabo_id → silabos

## Documentos y firmas

### exportaciones_silabo

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `revision_silabo_id` | uuid | no | FK → `revisiones_silabo` |
| `trabajo_id` | uuid | sí | FK → `trabajos_en_cola` |
| `archivo_word_id` | uuid | sí | FK → `archivos` |
| `archivo_pdf_id` | uuid | sí | FK → `archivos` |
| `clave_solicitud` | uuid | no |  |
| `estado_exportacion` | varchar | no |  |
| `solicitado_por` | uuid | no | FK → `usuarios` |
| `solicitado_en` | timestamptz | no |  |
| `completado_en` | timestamptz | sí |  |

### firmas_documento

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `exportacion_silabo_id` | uuid | no | FK → `exportaciones_silabo` |
| `firmado_como` | varchar | no |  |
| `usuario_id` | uuid | no | FK → `usuarios` |
| `archivo_firmado_id` | uuid | no | FK → `archivos` |
| `nombre_en_certificado` | varchar | no |  |
| `numero_certificado` | varchar | no |  |
| `entidad_emisora` | varchar | no |  |
| `certificado_valido_hasta` | timestamptz | no |  |
| `hash_archivo` | char | no |  |
| `firmado_en` | timestamptz | no |  |

### Relaciones: Documentos y firmas

- exportaciones_silabo.archivo_pdf_id → archivos
- exportaciones_silabo.archivo_word_id → archivos
- exportaciones_silabo.revision_silabo_id → revisiones_silabo
- exportaciones_silabo.solicitado_por → usuarios
- exportaciones_silabo.trabajo_id → trabajos_en_cola
- firmas_documento.archivo_firmado_id → archivos
- firmas_documento.exportacion_silabo_id → exportaciones_silabo
- firmas_documento.usuario_id → usuarios
