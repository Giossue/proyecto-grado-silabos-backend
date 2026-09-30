# Modelo entidad-relación de la base de datos

Base remota (`silabos_ueb_db`), 2026-09-30, tras la limpieza I-168: **42 tablas** y 68 relaciones.

**Llave:** `PK` = identificador de la fila; `FK → tabla` = apunta a otra tabla. **¿Vacío?:** si la columna puede quedar sin valor.

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
| `huella_activacion` | char | no |  |
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
| `actor_usuario_id` | uuid | sí | FK → `usuarios` |
| `asignacion_rol_id` | uuid | sí | FK → `asignaciones_rol` |
| `accion_evento_auditoria` | varchar | no |  |
| `tipo_recurso` | varchar | no |  |
| `recurso_id` | uuid | sí |  |
| `resultado_evento_auditoria` | varchar | no |  |
| `metadatos_evento_auditoria` | jsonb | sí |  |
| `correlacion_id` | uuid | sí |  |
| `ocurrido_en` | timestamptz | no |  |

### notificaciones_internas

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `usuario_id` | uuid | no | FK → `usuarios` |
| `clave_deduplicacion` | varchar | no |  |
| `tipo_notificacion_interna` | varchar | no |  |
| `titulo_notificacion_interna` | varchar | no |  |
| `mensaje_notificacion_interna` | text | no |  |
| `tipo_recurso` | varchar | sí |  |
| `recurso_id` | uuid | sí |  |
| `leido_en` | timestamptz | sí |  |
| `notificado_en` | timestamptz | no |  |

### ejecuciones_trabajo

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `tipo_ejecucion_trabajo` | varchar | no |  |
| `estado_ejecucion_trabajo` | varchar | no |  |
| `clave_idempotencia` | varchar | no |  |
| `correlacion_id` | uuid | sí |  |
| `intentos` | smallint | no |  |
| `progreso` | smallint | no |  |
| `resultado_ejecucion_trabajo` | jsonb | sí |  |
| `codigo_error` | varchar | sí |  |
| `mensaje_error` | text | sí |  |
| `iniciado_en` | timestamptz | sí |  |
| `finalizado_en` | timestamptz | sí |  |
| `encolado_en` | timestamptz | sí |  |
| `cola` | varchar | no |  |
| `tipo_recurso` | varchar | sí |  |
| `recurso_id` | uuid | sí |  |
| `intentos_maximos` | smallint | no |  |
| `entrada_ejecucion_trabajo` | jsonb | sí |  |

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

### objetos_almacenados

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `disco` | varchar | no |  |
| `ruta_interna` | varchar | no |  |
| `nombre_logico` | varchar | no |  |
| `mime` | varchar | no |  |
| `tamano_bytes` | bigint | no |  |
| `huella_sha256` | char | no |  |
| `clasificacion` | varchar | no |  |
| `estado_objeto_almacenado` | varchar | no |  |
| `propietario_usuario_id` | uuid | sí | FK → `usuarios` |
| `carrera_id` | uuid | sí | FK → `carreras` |
| `almacenado_en` | timestamptz | no |  |

### migraciones

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | integer | no | PK |
| `migration` | varchar | no |  |
| `batch` | integer | no |  |

### Relaciones: Identidad y operación

| Tabla | Columna | Apunta a | Cardinalidad (padre → hijos) |
|---|---|---|---|
| `asignaciones_rol` | `carrera_id` | `carreras` | 0 o 1 → muchos |
| `asignaciones_rol` | `usuario_id` | `usuarios` | 1 → muchos |
| `eventos_auditoria` | `actor_usuario_id` | `usuarios` | 0 o 1 → muchos |
| `eventos_auditoria` | `asignacion_rol_id` | `asignaciones_rol` | 0 o 1 → muchos |
| `invitaciones_activacion` | `emitida_por_usuario_id` | `usuarios` | 0 o 1 → muchos |
| `invitaciones_activacion` | `usuario_id` | `usuarios` | 1 → muchos |
| `notificaciones_internas` | `usuario_id` | `usuarios` | 1 → muchos |
| `objetos_almacenados` | `carrera_id` | `carreras` | 0 o 1 → muchos |
| `objetos_almacenados` | `propietario_usuario_id` | `usuarios` | 0 o 1 → muchos |
| `sesiones` | `user_id` | `usuarios` | 0 o 1 → muchos |

## Estructura académica

### facultades

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `nombre_facultad` | varchar | no |  |
| `facultad_activa` | boolean | no |  |
| `logo_objeto_id` | uuid | sí | FK → `objetos_almacenados` |
| `campus_facultad` | varchar | no |  |

### carreras

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `facultad_id` | uuid | no | FK → `facultades` |
| `codigo_carrera` | varchar | no |  |
| `nombre_carrera` | varchar | no |  |
| `carrera_activa` | boolean | no |  |
| `modalidad_carrera` | varchar | sí |  |
| `cantidad_ciclos_malla` | smallint | no |  |

### asignaturas

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `codigo_asignatura` | varchar | no |  |
| `nombre_asignatura` | varchar | no |  |
| `ciclo_asignatura` | smallint | sí |  |
| `creditos_asignatura` | numeric | sí |  |
| `asignatura_activa` | boolean | no |  |
| `horas_ac` | numeric | sí |  |
| `horas_pae` | numeric | sí |  |
| `horas_aa` | numeric | sí |  |
| `orden_asignatura_en_ciclo` | smallint | no |  |
| `unidad_organizativa_curricular_asignatura` | varchar | sí |  |
| `modalidad_asignatura` | varchar | sí |  |
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

### programaciones_asignatura

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `periodo_academico_id` | uuid | no | FK → `periodos_academicos` |
| `asignatura_id` | uuid | no | FK → `asignaturas` |
| `programacion_asignatura_activa` | boolean | no |  |

### paralelos

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `programacion_asignatura_id` | uuid | no | FK → `programaciones_asignatura` |
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
| `clave_idempotencia_importacion` | char | sí |  |
| `documentos_importacion` | jsonb | no |  |
| `propuesta_importacion` | jsonb | sí |  |
| `seleccion_aplicada` | jsonb | sí |  |
| `instrucciones_importacion` | text | sí |  |
| `mensaje_error_importacion` | text | sí |  |
| `solicitado_en` | timestamptz | no |  |
| `aplicado_en` | timestamptz | sí |  |

### fuentes_academicas

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `carrera_id` | uuid | no | FK → `carreras` |
| `nombre_fuente_academica` | varchar | no |  |
| `fuente_academica_activa` | boolean | no |  |
| `objeto_pdf_id` | uuid | sí | FK → `objetos_almacenados` |
| `estado_ingesta_fuente` | varchar | no |  |
| `ingesta_actualizada_en` | timestamptz | sí |  |
| `documento_fuente_academica` | jsonb | no |  |

### Relaciones: Estructura académica

| Tabla | Columna | Apunta a | Cardinalidad (padre → hijos) |
|---|---|---|---|
| `asignaciones_paralelo` | `asignacion_rol_id` | `asignaciones_rol` | 1 → muchos |
| `asignaciones_paralelo` | `paralelo_id` | `paralelos` | 1 → muchos |
| `asignaturas` | `carrera_id` | `carreras` | 1 → muchos |
| `carreras` | `facultad_id` | `facultades` | 1 → muchos |
| `facultades` | `logo_objeto_id` | `objetos_almacenados` | 0 o 1 → muchos |
| `fuentes_academicas` | `carrera_id` | `carreras` | 1 → muchos |
| `fuentes_academicas` | `objeto_pdf_id` | `objetos_almacenados` | 0 o 1 → muchos |
| `importaciones_curriculares` | `carrera_id` | `carreras` | 1 → muchos |
| `importaciones_curriculares` | `solicitado_por` | `usuarios` | 1 → muchos |
| `paralelos` | `programacion_asignatura_id` | `programaciones_asignatura` | 1 → muchos |
| `programaciones_asignatura` | `asignatura_id` | `asignaturas` | 1 → muchos |
| `programaciones_asignatura` | `periodo_academico_id` | `periodos_academicos` | 1 → muchos |
| `requisitos_asignatura` | `asignatura_id` | `asignaturas` | 1 → muchos |
| `requisitos_asignatura` | `requisito_id` | `asignaturas` | 1 → muchos |
| `valores_curriculares` | `asignatura_id` | `asignaturas` | 1 → muchos |
| `valores_curriculares` | `concepto_id` | `conceptos_curriculares` | 1 → muchos |
| `valores_curriculares` | `confirmado_por` | `usuarios` | 1 → muchos |

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
| `origen_maestro_definicion_campo` | varchar | sí |  |
| `editable_docente_definicion_campo` | boolean | no |  |
| `opciones_definicion_campo` | jsonb | sí |  |
| `posicion_definicion_campo` | smallint | no |  |
| `plantilla_id` | uuid | no | FK → `plantilla_silabo` |
| `mostrar_si_definicion_campo` | jsonb | sí |  |

### Relaciones: Plantilla del sílabo

| Tabla | Columna | Apunta a | Cardinalidad (padre → hijos) |
|---|---|---|---|
| `campos_plantilla` | `plantilla_id` | `plantilla_silabo` | 1 → muchos |
| `campos_plantilla` | `seccion_plantilla_id` | `secciones_plantilla` | 1 → muchos |
| `definiciones_campo` | `campo_plantilla_id` | `campos_plantilla` | 1 → muchos |
| `definiciones_campo` | `plantilla_id` | `plantilla_silabo` | 1 → muchos |
| `secciones_plantilla` | `plantilla_id` | `plantilla_silabo` | 1 → muchos |

## Convocatorias, sílabos y revisión

### convocatorias_universidad

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `inicia_en` | timestamptz | no |  |
| `entrega_en` | timestamptz | no |  |
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
| `version_bloqueo` | integer | no |  |
| `iniciado_en` | timestamptz | sí |  |
| `guardado_en` | timestamptz | sí |  |
| `contexto_academico_silabo` | jsonb | no |  |
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
| `clave_idempotencia` | uuid | no |  |
| `version_bloqueo_origen` | integer | no |  |
| `fotografia_revision_silabo` | jsonb | no |  |
| `huella_sha256` | char | no |  |
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
| `clave_idempotencia` | uuid | no |  |
| `huella_sha256` | char | no |  |
| `aprobado_por` | uuid | no | FK → `usuarios` |
| `aprobado_en` | timestamptz | no |  |
| `aprobacion_base_id` | uuid | sí | FK → `aprobaciones` |

### reaperturas

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `aprobacion_id` | uuid | no | FK → `aprobaciones` |
| `revision_aprobada_id` | uuid | no | FK → `revisiones_silabo` |
| `clave_idempotencia` | uuid | no |  |
| `causa` | text | no |  |
| `reabierto_por` | uuid | no | FK → `usuarios` |
| `reabierto_en` | timestamptz | no |  |
| `origen_reapertura` | varchar | no |  |

### ejecuciones_validacion

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `silabo_id` | uuid | no | FK → `silabos` |
| `ejecutado_por` | uuid | no | FK → `usuarios` |
| `version_reglas` | varchar | no |  |
| `estado_ejecucion_validacion` | varchar | no |  |
| `version_bloqueo` | integer | no |  |
| `cantidad_errores_validacion` | smallint | no |  |
| `cantidad_advertencias_validacion` | smallint | no |  |
| `completado_en` | timestamptz | no |  |

### resultados_validacion

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `ejecucion_validacion_id` | uuid | no | FK → `ejecuciones_validacion` |
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

### historial_ia

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `sesion_id` | text | no |  |
| `mensaje_ia` | jsonb | no |  |
| `enviado_en` | timestamptz | no |  |

### Relaciones: Convocatorias, sílabos y revisión

| Tabla | Columna | Apunta a | Cardinalidad (padre → hijos) |
|---|---|---|---|
| `aprobaciones` | `aprobacion_base_id` | `aprobaciones` | 0 o 1 → muchos |
| `aprobaciones` | `aprobado_por` | `usuarios` | 1 → muchos |
| `aprobaciones` | `revision_silabo_id` | `revisiones_silabo` | 1 → 0 o 1 |
| `convocatorias_carreras` | `carrera_id` | `carreras` | 1 → muchos |
| `convocatorias_carreras` | `convocatoria_universidad_id` | `convocatorias_universidad` | 1 → muchos |
| `convocatorias_universidad` | `periodo_academico_id` | `periodos_academicos` | 1 → muchos |
| `ejecuciones_validacion` | `ejecutado_por` | `usuarios` | 1 → muchos |
| `ejecuciones_validacion` | `silabo_id` | `silabos` | 1 → muchos |
| `envios_biblioteca` | `enviado_por` | `usuarios` | 1 → muchos |
| `envios_biblioteca` | `revision_silabo_id` | `revisiones_silabo` | 1 → 0 o 1 |
| `envios_biblioteca` | `verificado_por` | `usuarios` | 0 o 1 → muchos |
| `observaciones_revision` | `creado_por` | `usuarios` | 1 → muchos |
| `observaciones_revision` | `revision_silabo_id` | `revisiones_silabo` | 1 → muchos |
| `reaperturas` | `aprobacion_id` | `aprobaciones` | 1 → 0 o 1 |
| `reaperturas` | `reabierto_por` | `usuarios` | 1 → muchos |
| `reaperturas` | `revision_aprobada_id` | `revisiones_silabo` | 1 → muchos |
| `resultados_validacion` | `definicion_campo_id` | `definiciones_campo` | 0 o 1 → muchos |
| `resultados_validacion` | `ejecucion_validacion_id` | `ejecuciones_validacion` | 1 → muchos |
| `revisiones_silabo` | `enviado_por` | `usuarios` | 1 → muchos |
| `revisiones_silabo` | `reapertura_id` | `reaperturas` | 0 o 1 → muchos |
| `revisiones_silabo` | `revision_anterior_id` | `revisiones_silabo` | 0 o 1 → muchos |
| `revisiones_silabo` | `silabo_id` | `silabos` | 1 → muchos |
| `silabos` | `convocatoria_carrera_id` | `convocatorias_carreras` | 1 → muchos |
| `silabos` | `paralelo_id` | `paralelos` | 1 → muchos |
| `solicitudes_correccion` | `revision_silabo_id` | `revisiones_silabo` | 1 → 0 o 1 |
| `solicitudes_correccion` | `solicitado_por` | `usuarios` | 1 → muchos |
| `valores_campo` | `definicion_campo_id` | `definiciones_campo` | 1 → muchos |
| `valores_campo` | `silabo_id` | `silabos` | 1 → muchos |

## Documentos y firmas

### artefactos_exportacion

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `revision_silabo_id` | uuid | no | FK → `revisiones_silabo` |
| `ejecucion_trabajo_id` | uuid | sí | FK → `ejecuciones_trabajo` |
| `objeto_docx_id` | uuid | sí | FK → `objetos_almacenados` |
| `objeto_pdf_id` | uuid | sí | FK → `objetos_almacenados` |
| `version_renderizador` | varchar | no |  |
| `idioma` | varchar | no |  |
| `clave_idempotencia` | uuid | no |  |
| `estado_artefacto_exportacion` | varchar | no |  |
| `solicitado_por` | uuid | no | FK → `usuarios` |
| `solicitado_en` | timestamptz | no |  |
| `completado_en` | timestamptz | sí |  |

### firmas_documento

| Columna | Tipo | ¿Vacío? | Llave |
|---|---|---|---|
| `id` | uuid | no | PK |
| `artefacto_exportacion_id` | uuid | no | FK → `artefactos_exportacion` |
| `rol_firma` | varchar | no |  |
| `usuario_id` | uuid | no | FK → `usuarios` |
| `objeto_almacenado_id` | uuid | no | FK → `objetos_almacenados` |
| `certificado_titular` | varchar | no |  |
| `certificado_serie` | varchar | no |  |
| `certificado_emisor` | varchar | no |  |
| `certificado_vence_en` | timestamptz | no |  |
| `huella_sha256` | char | no |  |
| `firmado_en` | timestamptz | no |  |

### Relaciones: Documentos y firmas

| Tabla | Columna | Apunta a | Cardinalidad (padre → hijos) |
|---|---|---|---|
| `artefactos_exportacion` | `ejecucion_trabajo_id` | `ejecuciones_trabajo` | 0 o 1 → 0 o 1 |
| `artefactos_exportacion` | `objeto_docx_id` | `objetos_almacenados` | 0 o 1 → 0 o 1 |
| `artefactos_exportacion` | `objeto_pdf_id` | `objetos_almacenados` | 0 o 1 → 0 o 1 |
| `artefactos_exportacion` | `revision_silabo_id` | `revisiones_silabo` | 1 → muchos |
| `artefactos_exportacion` | `solicitado_por` | `usuarios` | 1 → muchos |
| `firmas_documento` | `artefacto_exportacion_id` | `artefactos_exportacion` | 1 → muchos |
| `firmas_documento` | `objeto_almacenado_id` | `objetos_almacenados` | 1 → 0 o 1 |
| `firmas_documento` | `usuario_id` | `usuarios` | 1 → muchos |
