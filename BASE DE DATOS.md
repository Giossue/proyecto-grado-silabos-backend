# Tablas de la base de datos — esquema objetivo con I-190

Estructura objetivo tras `2026_10_05_000197_teacher_formation_in_user_profile`. Los conteos de filas son la última observación remota anterior a I-189; «—» indica una tabla todavía no observada allí. Este documento no acredita que la migración se haya aplicado en producción. No incluye contenido de filas.

- Tablas objetivo: 32 (29 del dominio y 3 del framework Laravel: migraciones, restablecimientos_contrasena, sesiones)
- Vistas: docentes_vigentes_silabo
- Cada tabla del dominio tiene una sola llave con el nombre de la entidad (`paralelos.paralelo_id`); `id` queda solo en `migraciones` y `sesiones`, que fija Laravel (I-179). Sin `trabajos_fallidos` (I-183).
- Sin `asignaturas_ofertadas`: el paralelo apunta directo al período y a la materia (I-181).
- Escritura única: solo las columnas `correccion_*` de `revisiones_silabo`.

## Índice

| Tabla | Llave primaria | Columnas | Filas en la remota |
|---|---|---|---|
| [aprobaciones](#aprobaciones) | (aprobacion_id) | 7 | 0 |
| [archivos](#archivos) | (archivo_id) | 10 | 1 |
| [asignaciones_paralelo](#asignaciones_paralelo) | (asignacion_paralelo_id) | 5 | 0 |
| [asignaciones_rol](#asignaciones_rol) | (asignacion_rol_id) | 7 | 6 |
| [asignaturas](#asignaturas) | (asignatura_id) | 14 | 43 |
| [campos_plantilla](#campos_plantilla) | (campo_plantilla_id) | 7 | 21 |
| [carreras](#carreras) | (carrera_id) | 7 | 1 |
| [convocatorias_carreras](#convocatorias_carreras) | (convocatoria_carrera_id) | 4 | 0 |
| [convocatorias_universidad](#convocatorias_universidad) | (convocatoria_universidad_id) | 5 | 0 |
| [definiciones_campo](#definiciones_campo) | (definicion_campo_id) | 13 | 21 |
| [envios_biblioteca](#envios_biblioteca) | (envio_biblioteca_id) | 4 | 0 |
| [eventos_auditoria](#eventos_auditoria) | (evento_auditoria_id) | 9 | 594 |
| [exportaciones_silabo](#exportaciones_silabo) | (exportacion_silabo_id) | 10 | 0 |
| [facultades](#facultades) | (facultad_id) | 5 | 1 |
| [firmas_documento](#firmas_documento) | (firma_documento_id) | 10 | 0 |
| [invitaciones_activacion](#invitaciones_activacion) | (invitacion_activacion_id) | 8 | 8 |
| [mensajes_ia](#mensajes_ia) | (mensaje_ia_id) | 5 | 0 |
| [migraciones](#migraciones) | (id) | 3 | 153 |
| [notificaciones](#notificaciones) | (notificacion_id) | 10 | 96 |
| [paralelos](#paralelos) | (paralelo_id) | 5 | 0 |
| [periodos_academicos](#periodos_academicos) | (periodo_academico_id) | 5 | 0 |
| [plantilla_silabo](#plantilla_silabo) | (plantilla_id) | 2 | 1 |
| [requisitos_asignatura](#requisitos_asignatura) | (requisito_asignatura_id) | 4 | 27 |
| [restablecimientos_contrasena](#restablecimientos_contrasena) | (email) | 3 | 0 |
| [revisiones_coordinacion](#revisiones_coordinacion) | (revision_coordinacion_id) | 4 | 0 |
| [revisiones_silabo](#revisiones_silabo) | (revision_silabo_id) | 14 | 0 |
| [secciones_plantilla](#secciones_plantilla) | (seccion_plantilla_id) | 6 | 12 |
| [sesiones](#sesiones) | (id) | 6 | 3 |
| [silabos](#silabos) | (silabo_id) | 8 | 0 |
| [trabajos_en_cola](#trabajos_en_cola) | (trabajo_id) | 14 | 107 |
| [usuarios](#usuarios) | (usuario_id) | 10 | 6 |
| [valores_campo](#valores_campo) | (valor_campo_id) | 5 | 0 |

## aprobaciones

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `aprobacion_id` | UUID | no |  |
| `revision_silabo_id` | UUID | no |  |
| `clave_solicitud` | UUID | no |  |
| `hash_contenido` | CHAR(64) | no |  |
| `aprobado_por` | UUID | no |  |
| `aprobado_en` | TIMESTAMPTZ | no |  |
| `aprobacion_anterior_id` | UUID | sí |  |

**Restricciones**

- FK `aprobaciones_aprobacion_anterior_id_foreign`: FOREIGN KEY (aprobacion_anterior_id) REFERENCES aprobaciones(aprobacion_id) ON DELETE RESTRICT
- FK `aprobaciones_aprobado_por_foreign`: FOREIGN KEY (aprobado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- FK `aprobaciones_revision_silabo_id_foreign`: FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT
- PK `aprobaciones_pkey`: PRIMARY KEY (aprobacion_id)
- Único `aprobaciones_revision_silabo_id_unique`: UNIQUE (revision_silabo_id)

**Índices**

- `aprobaciones_aprobacion_anterior_id_index`: `btree (aprobacion_anterior_id)`
- `aprobaciones_aprobado_por_index`: `btree (aprobado_por)`

## archivos

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `archivo_id` | UUID | no |  |
| `disco` | VARCHAR(80) | no |  |
| `ruta_archivo` | VARCHAR(500) | no |  |
| `nombre_archivo` | VARCHAR(255) | no |  |
| `tipo_archivo` | VARCHAR(120) | no |  |
| `tamano_bytes` | BIGINT | no |  |
| `hash_archivo` | CHAR(64) | no |  |
| `categoria` | VARCHAR(80) | no |  |
| `subido_por` | UUID | sí |  |
| `almacenado_en` | TIMESTAMPTZ | no |  |

**Restricciones**

- FK `archivos_subido_por_foreign`: FOREIGN KEY (subido_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- PK `archivos_pkey`: PRIMARY KEY (archivo_id)
- Único `archivos_disco_ruta_archivo_unique`: UNIQUE (disco, ruta_archivo)

**Índices**

- `archivos_categoria_almacenado_en_index`: `btree (categoria, almacenado_en)`
- `archivos_hash_archivo_index`: `btree (hash_archivo)`
- `archivos_subido_por_index`: `btree (subido_por)`

## asignaciones_paralelo

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `asignacion_paralelo_id` | UUID | no |  |
| `paralelo_id` | UUID | no |  |
| `asignacion_paralelo_activa` | BOOLEAN | no | `true` |
| `asignado_en` | TIMESTAMPTZ | no | `CURRENT_TIMESTAMP` |
| `asignacion_rol_id` | UUID | no |  |

**Restricciones**

- FK `asignaciones_paralelo_asignacion_rol_id_foreign`: FOREIGN KEY (asignacion_rol_id) REFERENCES asignaciones_rol(asignacion_rol_id) ON DELETE RESTRICT
- FK `asignaciones_paralelo_paralelo_id_foreign`: FOREIGN KEY (paralelo_id) REFERENCES paralelos(paralelo_id) ON DELETE RESTRICT
- PK `asignaciones_paralelo_pkey`: PRIMARY KEY (asignacion_paralelo_id)

**Índices**

- `asignaciones_paralelo_activa_idx`: `btree (asignacion_rol_id, asignacion_paralelo_activa)`
- `asignaciones_paralelo_paralelo_activo_unico`: `btree (paralelo_id) WHERE asignacion_paralelo_activa`

## asignaciones_rol

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `asignacion_rol_id` | UUID | no |  |
| `usuario_id` | UUID | no |  |
| `carrera_id` | UUID | sí |  |
| `asignacion_rol_activa` | BOOLEAN | no | `true` |
| `asignado_en` | TIMESTAMPTZ | no | `CURRENT_TIMESTAMP` |
| `rol` | VARCHAR(20) | no |  |
| `facultad_id` | UUID | sí |  |

**Restricciones**

- FK `asignaciones_rol_carrera_id_foreign`: FOREIGN KEY (carrera_id) REFERENCES carreras(carrera_id) ON DELETE RESTRICT
- FK `asignaciones_rol_facultad_id_fkey`: FOREIGN KEY (facultad_id) REFERENCES facultades(facultad_id) ON DELETE RESTRICT
- FK `asignaciones_rol_usuario_id_foreign`: FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- PK `asignaciones_rol_pkey`: PRIMARY KEY (asignacion_rol_id)

**Checks**

- `asignaciones_rol_alcance_valido_check`: CHECK (((((rol)::text = ANY ((ARRAY['administrador'::character varying, 'bibliotecario'::character varying])::text[])) AND (carrera_id IS NULL) AND (facultad_id IS NULL)) OR (((rol)::text = ANY ((ARRAY['coordinador'::character varying, 'docente'::character varying])::text[])) AND (carrera_id IS NOT NULL) AND (facultad_id IS NULL)) OR (((rol)::text = 'decano'::text) AND (carrera_id IS NULL) AND (facultad_id IS NOT NULL))))
- `asignaciones_rol_rol_valido_check`: CHECK (((rol)::text = ANY ((ARRAY['administrador'::character varying, 'coordinador'::character varying, 'docente'::character varying, 'bibliotecario'::character varying, 'decano'::character varying])::text[])))

**Índices**

- `asignaciones_rol_activa_unica`: `btree (usuario_id, rol, COALESCE(carrera_id, '00000000-0000-0000-0000-000000000000'::uuid)) WHERE asignacion_rol_activa`
- `asignaciones_rol_carrera_id_index`: `btree (carrera_id)`
- `asignaciones_rol_decano_facultad_unica`: `btree (facultad_id) WHERE (((rol)::text = 'decano'::text) AND asignacion_rol_activa)`
- `asignaciones_rol_facultad_id_index`: `btree (facultad_id)`

## asignaturas

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `asignatura_id` | UUID | no |  |
| `codigo_asignatura` | VARCHAR(80) | no |  |
| `nombre_asignatura` | VARCHAR(180) | no |  |
| `ciclo_asignatura` | SMALLINT | sí |  |
| `creditos_asignatura` | NUMERIC | sí |  |
| `asignatura_activa` | BOOLEAN | no | `true` |
| `horas_docencia` | NUMERIC | sí |  |
| `horas_practicas` | NUMERIC | sí |  |
| `horas_autonomas` | NUMERIC | sí |  |
| `orden_asignatura_en_ciclo` | SMALLINT | no | `'0'::smallint` |
| `unidad_curricular_asignatura` | VARCHAR(30) | sí |  |
| `modalidad_asignatura` | USER-DEFINED | sí |  |
| `carrera_id` | UUID | no |  |
| `total_horas_asignatura` | SMALLINT | sí |  |

**Restricciones**

- FK `asignaturas_carrera_id_foreign`: FOREIGN KEY (carrera_id) REFERENCES carreras(carrera_id) ON DELETE RESTRICT
- PK `asignaturas_pkey`: PRIMARY KEY (asignatura_id)
- Único `asignaturas_carrera_id_codigo_asignatura_unique`: UNIQUE (carrera_id, codigo_asignatura)
- Único `asignaturas_identidad_carrera_unica`: UNIQUE (asignatura_id, carrera_id)

**Checks**

- `asignaturas_unidad_curricular_check`: CHECK (((unidad_curricular_asignatura)::text = ANY ((ARRAY['basica'::character varying, 'profesional'::character varying, 'integracion_curricular'::character varying])::text[])))

## campos_plantilla

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `campo_plantilla_id` | UUID | no |  |
| `seccion_plantilla_id` | UUID | no |  |
| `plantilla_id` | UUID | no |  |
| `clave_campo_plantilla` | VARCHAR(120) | no |  |
| `titulo_campo_plantilla` | VARCHAR(180) | no | `''::character varying` |
| `posicion_campo_plantilla` | SMALLINT | no |  |
| `configuracion_campo_plantilla` | JSONB | no |  |
| `vigente_en_plantilla` | BOOLEAN | no | `true` |

**Restricciones**

- FK `campos_plantilla_plantilla_id_foreign`: FOREIGN KEY (plantilla_id) REFERENCES plantilla_silabo(plantilla_id) ON DELETE CASCADE
- FK `campos_plantilla_seccion_misma_plantilla_foreign`: FOREIGN KEY (seccion_plantilla_id, plantilla_id) REFERENCES secciones_plantilla(seccion_plantilla_id, plantilla_id) ON DELETE RESTRICT
- FK `campos_plantilla_seccion_plantilla_id_foreign`: FOREIGN KEY (seccion_plantilla_id) REFERENCES secciones_plantilla(seccion_plantilla_id) ON DELETE CASCADE
- PK `campos_plantilla_pkey`: PRIMARY KEY (campo_plantilla_id)
- Único parcial `campos_plantilla_vigente_clave_unica`: UNIQUE (plantilla_id, clave_campo_plantilla) WHERE vigente_en_plantilla
- Único `campos_plantilla_id_plantilla_unica`: UNIQUE (campo_plantilla_id, plantilla_id)

**Índices**

- `campos_plantilla_seccion_plantilla_id_posicion_index`: `btree (seccion_plantilla_id, posicion_campo_plantilla)`

## carreras

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `carrera_id` | UUID | no |  |
| `facultad_id` | UUID | no |  |
| `codigo_carrera` | VARCHAR(80) | no |  |
| `nombre_carrera` | VARCHAR(180) | no |  |
| `carrera_activa` | BOOLEAN | no | `true` |
| `modalidad_carrera` | USER-DEFINED | sí |  |
| `numero_ciclos` | SMALLINT | no |  |

**Restricciones**

- FK `carreras_facultad_id_foreign`: FOREIGN KEY (facultad_id) REFERENCES facultades(facultad_id) ON DELETE RESTRICT
- PK `carreras_pkey`: PRIMARY KEY (carrera_id)
- Único `carreras_codigo_carrera_unique`: UNIQUE (codigo_carrera)

**Checks**

- `carreras_numero_ciclos_check`: CHECK (((numero_ciclos >= 1) AND (numero_ciclos <= 30)))

**Índices**

- `carreras_facultad_id_index`: `btree (facultad_id)`

## convocatorias_carreras

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `convocatoria_carrera_id` | UUID | no |  |
| `carrera_id` | UUID | no |  |
| `estado_convocatoria_carrera` | VARCHAR(24) | no | `'preparacion'::character varying` |
| `convocatoria_universidad_id` | UUID | no |  |

**Restricciones**

- FK `convocatorias_carreras_carrera_id_foreign`: FOREIGN KEY (carrera_id) REFERENCES carreras(carrera_id) ON DELETE RESTRICT
- FK `convocatorias_carreras_convocatoria_universidad_id_foreign`: FOREIGN KEY (convocatoria_universidad_id) REFERENCES convocatorias_universidad(convocatoria_universidad_id) ON DELETE RESTRICT
- PK `convocatorias_carreras_pkey`: PRIMARY KEY (convocatoria_carrera_id)

**Checks**

- `convocatorias_carreras_estado_check`: CHECK (((estado_convocatoria_carrera)::text = ANY ((ARRAY['preparacion'::character varying, 'abierta'::character varying, 'pausada'::character varying, 'cerrada'::character varying])::text[])))

**Índices**

- `convocatorias_carreras_carrera_id_estado_index`: `btree (carrera_id, estado_convocatoria_carrera)`
- `convocatorias_carreras_carrera_proceso_unico`: `btree (carrera_id, convocatoria_universidad_id)`
- `convocatorias_carreras_proceso_id_index`: `btree (convocatoria_universidad_id)`

## convocatorias_universidad

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `convocatoria_universidad_id` | UUID | no |  |
| `fecha_inicio` | TIMESTAMPTZ | no |  |
| `fecha_entrega` | TIMESTAMPTZ | no |  |
| `estado_convocatoria_universidad` | VARCHAR(20) | no | `'abierto'::character varying` |
| `periodo_academico_id` | UUID | no |  |
| `pausado_en` | TIMESTAMPTZ | sí |  |

**Restricciones**

- FK `convocatorias_universidad_periodo_academico_id_foreign`: FOREIGN KEY (periodo_academico_id) REFERENCES periodos_academicos(periodo_academico_id) ON DELETE RESTRICT
- PK `convocatorias_universidad_pkey`: PRIMARY KEY (convocatoria_universidad_id)

**Checks**

- `convocatorias_universidad_estado_check`: CHECK (((estado_convocatoria_universidad)::text = ANY ((ARRAY['abierto'::character varying, 'pausado'::character varying, 'cerrado'::character varying])::text[])))
- `convocatorias_universidad_fechas_check`: CHECK ((fecha_entrega > fecha_inicio))

**Índices**

- `convocatorias_universidad_en_curso_unico`: `btree ((1)) WHERE ((estado_convocatoria_universidad)::text = ANY ((ARRAY['abierto'::character varying, 'pausado'::character varying])::text[]))`
- `convocatorias_universidad_periodo_unico`: `btree (periodo_academico_id)`

## definiciones_campo

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `definicion_campo_id` | UUID | no |  |
| `campo_plantilla_id` | UUID | no |  |
| `clave_definicion_campo` | VARCHAR(120) | no |  |
| `etiqueta_definicion_campo` | VARCHAR(180) | no |  |
| `ayuda_definicion_campo` | TEXT | sí |  |
| `tipo_definicion_campo` | VARCHAR(40) | no |  |
| `heredado_definicion_campo` | BOOLEAN | no | `false` |
| `origen_dato_definicion_campo` | VARCHAR(100) | sí |  |
| `editable_docente_definicion_campo` | BOOLEAN | no | `true` |
| `opciones_definicion_campo` | JSONB | sí |  |
| `posicion_definicion_campo` | SMALLINT | no |  |
| `plantilla_id` | UUID | no |  |
| `condicion_definicion_campo` | JSONB | sí |  |
| `vigente_en_plantilla` | BOOLEAN | no | `true` |

**Restricciones**

- FK `definiciones_campo_campo_misma_plantilla_foreign`: FOREIGN KEY (campo_plantilla_id, plantilla_id) REFERENCES campos_plantilla(campo_plantilla_id, plantilla_id) ON DELETE RESTRICT
- FK `definiciones_campo_campo_plantilla_id_foreign`: FOREIGN KEY (campo_plantilla_id) REFERENCES campos_plantilla(campo_plantilla_id) ON DELETE CASCADE
- FK `definiciones_campo_plantilla_id_foreign`: FOREIGN KEY (plantilla_id) REFERENCES plantilla_silabo(plantilla_id) ON DELETE CASCADE
- PK `definiciones_campo_pkey`: PRIMARY KEY (definicion_campo_id)
- Único `definiciones_campo_campo_id_posicion_unica`: UNIQUE (campo_plantilla_id, posicion_definicion_campo)
- Único parcial `definiciones_campo_vigente_clave_unica`: UNIQUE (plantilla_id, clave_definicion_campo) WHERE vigente_en_plantilla

**Checks**

- `definiciones_campo_heredado_check`: CHECK (NOT heredado_definicion_campo OR origen_dato_definicion_campo IS NOT NULL)
- `definiciones_campo_tipo_check`: CHECK (((tipo_definicion_campo)::text = ANY ((ARRAY['texto_corto'::character varying, 'texto_largo'::character varying, 'texto_libre'::character varying, 'numero'::character varying, 'fecha'::character varying, 'seleccion_unica'::character varying, 'seleccion_multiple'::character varying, 'booleano'::character varying, 'calculo'::character varying, 'referencia_maestra'::character varying])::text[])))

## envios_biblioteca

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `envio_biblioteca_id` | UUID | no |  |
| `revision_silabo_id` | UUID | no |  |
| `verificado_por` | UUID | sí |  |
| `verificado_en` | TIMESTAMPTZ | sí |  |

**Restricciones**

- FK `envios_biblioteca_revision_silabo_id_foreign`: FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT
- FK `envios_biblioteca_verificado_por_foreign`: FOREIGN KEY (verificado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- PK `envios_biblioteca_pkey`: PRIMARY KEY (envio_biblioteca_id)
- Único `envios_biblioteca_revision_unica`: UNIQUE (revision_silabo_id)

**Checks**

- `envios_biblioteca_verificacion_completa`: CHECK (((verificado_por IS NULL) = (verificado_en IS NULL)))

**Índices**

- `envios_biblioteca_verificado_por_index`: `btree (verificado_por)`

## eventos_auditoria

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `evento_auditoria_id` | UUID | no |  |
| `usuario_id` | UUID | sí |  |
| `asignacion_rol_id` | UUID | sí |  |
| `accion` | VARCHAR(120) | no |  |
| `tipo_recurso` | VARCHAR(120) | no |  |
| `recurso_id` | UUID | sí |  |
| `resultado` | VARCHAR(24) | no |  |
| `detalles` | JSONB | sí |  |
| `ocurrido_en` | TIMESTAMPTZ | no |  |

**Restricciones**

- FK `eventos_auditoria_asignacion_rol_id_foreign`: FOREIGN KEY (asignacion_rol_id) REFERENCES asignaciones_rol(asignacion_rol_id) ON DELETE RESTRICT
- FK `eventos_auditoria_usuario_id_foreign`: FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- PK `eventos_auditoria_pkey`: PRIMARY KEY (evento_auditoria_id)

**Checks**

- `eventos_auditoria_resultado_check`: CHECK (((resultado)::text = ANY ((ARRAY['exito'::character varying, 'fallido'::character varying])::text[])))

**Índices**

- `eventos_auditoria_asignacion_rol_id_index`: `btree (asignacion_rol_id)`
- `eventos_auditoria_ocurrido_en_index`: `btree (ocurrido_en)`
- `eventos_auditoria_recurso_fecha_idx`: `btree (tipo_recurso, recurso_id, ocurrido_en)`
- `eventos_auditoria_usuario_fecha_idx`: `btree (usuario_id, ocurrido_en)`

## exportaciones_silabo

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `exportacion_silabo_id` | UUID | no |  |
| `revision_silabo_id` | UUID | no |  |
| `trabajo_id` | UUID | sí |  |
| `archivo_word_id` | UUID | sí |  |
| `archivo_pdf_id` | UUID | sí |  |
| `clave_solicitud` | UUID | no |  |
| `estado_exportacion` | VARCHAR(24) | no | `'pendiente'::character varying` |
| `solicitado_por` | UUID | no |  |
| `solicitado_en` | TIMESTAMPTZ | no |  |
| `completado_en` | TIMESTAMPTZ | sí |  |

**Restricciones**

- FK `exportaciones_silabo_archivo_pdf_id_foreign`: FOREIGN KEY (archivo_pdf_id) REFERENCES archivos(archivo_id) ON DELETE RESTRICT
- FK `exportaciones_silabo_archivo_word_id_foreign`: FOREIGN KEY (archivo_word_id) REFERENCES archivos(archivo_id) ON DELETE RESTRICT
- FK `exportaciones_silabo_revision_silabo_id_foreign`: FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT
- FK `exportaciones_silabo_solicitado_por_foreign`: FOREIGN KEY (solicitado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- FK `exportaciones_silabo_trabajo_id_foreign`: FOREIGN KEY (trabajo_id) REFERENCES trabajos_en_cola(trabajo_id) ON DELETE RESTRICT
- PK `exportaciones_silabo_pkey`: PRIMARY KEY (exportacion_silabo_id)
- Único `exportaciones_silabo_archivo_pdf_id_unique`: UNIQUE (archivo_pdf_id)
- Único `exportaciones_silabo_archivo_word_id_unique`: UNIQUE (archivo_word_id)
- Único `exportaciones_silabo_revision_clave_solicitud_unica`: UNIQUE (revision_silabo_id, clave_solicitud)
- Único `exportaciones_silabo_trabajo_id_unique`: UNIQUE (trabajo_id)

**Checks**

- `exportaciones_silabo_completitud_check`: CHECK (((((estado_exportacion)::text = 'completado'::text) AND (archivo_word_id IS NOT NULL) AND (archivo_pdf_id IS NOT NULL) AND (completado_en IS NOT NULL)) OR ((estado_exportacion)::text <> 'completado'::text)))
- `exportaciones_silabo_estado_check`: CHECK (((estado_exportacion)::text = ANY ((ARRAY['pendiente'::character varying, 'en_ejecucion'::character varying, 'completado'::character varying, 'fallido'::character varying])::text[])))

**Índices**

- `exportaciones_silabo_revision_estado_solicitado_index`: `btree (revision_silabo_id, estado_exportacion, solicitado_en)`
- `exportaciones_silabo_solicitado_por_index`: `btree (solicitado_por)`

## facultades

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `facultad_id` | UUID | no |  |
| `nombre_facultad` | VARCHAR(180) | no |  |
| `facultad_activa` | BOOLEAN | no | `true` |
| `logo_archivo_id` | UUID | sí |  |
| `campus_facultad` | VARCHAR(255) | no |  |

**Restricciones**

- FK `facultades_logo_archivo_id_foreign`: FOREIGN KEY (logo_archivo_id) REFERENCES archivos(archivo_id) ON DELETE RESTRICT
- PK `facultades_pkey`: PRIMARY KEY (facultad_id)

**Checks**

- `facultades_campus_facultad_check`: CHECK (((campus_facultad)::text = ANY ((ARRAY['Campus Matriz Dr. Gabriel Galarza López'::character varying, 'Campus Laguacoto'::character varying, 'Campus CENI (Centro de Eventos Nacionales e Internacionales)'::character varying, 'Extensión San Miguel'::character varying, 'Sede Chimbo'::character varying])::text[])))

**Índices**

- `facultades_logo_archivo_id_index`: `btree (logo_archivo_id)`

## firmas_documento

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `firma_documento_id` | UUID | no |  |
| `exportacion_silabo_id` | UUID | no |  |
| `firmado_como` | VARCHAR(16) | no |  |
| `usuario_id` | UUID | no |  |
| `archivo_firmado_id` | UUID | no |  |
| `nombre_en_certificado` | VARCHAR(255) | no |  |
| `numero_certificado` | VARCHAR(255) | no |  |
| `entidad_emisora` | VARCHAR(500) | no |  |
| `certificado_valido_hasta` | TIMESTAMPTZ | no |  |
| `firmado_en` | TIMESTAMPTZ | no |  |

**Restricciones**

- FK `firmas_documento_archivo_firmado_id_foreign`: FOREIGN KEY (archivo_firmado_id) REFERENCES archivos(archivo_id) ON DELETE RESTRICT
- FK `firmas_documento_exportacion_silabo_id_foreign`: FOREIGN KEY (exportacion_silabo_id) REFERENCES exportaciones_silabo(exportacion_silabo_id) ON DELETE RESTRICT
- FK `firmas_documento_usuario_id_foreign`: FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- PK `firmas_documento_pkey`: PRIMARY KEY (firma_documento_id)
- Único `firmas_documento_archivo_firmado_unico`: UNIQUE (archivo_firmado_id)
- Único `firmas_documento_firmado_como_unico`: UNIQUE (exportacion_silabo_id, firmado_como)

**Checks**

- `firmas_documento_firmado_como_check`: CHECK (((firmado_como)::text = ANY ((ARRAY['docente'::character varying, 'coordinacion'::character varying, 'biblioteca'::character varying, 'decanato'::character varying])::text[])))

**Índices**

- `firmas_documento_usuario_id_index`: `btree (usuario_id)`

## invitaciones_activacion

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `invitacion_activacion_id` | UUID | no |  |
| `usuario_id` | UUID | no |  |
| `hash_activacion` | CHAR(64) | no |  |
| `expira_en` | TIMESTAMPTZ | no |  |
| `usada_en` | TIMESTAMPTZ | sí |  |
| `invalidada_en` | TIMESTAMPTZ | sí |  |
| `emitida_por_usuario_id` | UUID | sí |  |
| `creada_en` | TIMESTAMPTZ | no | `CURRENT_TIMESTAMP` |

**Restricciones**

- FK `invitaciones_activacion_emitida_por_usuario_id_foreign`: FOREIGN KEY (emitida_por_usuario_id) REFERENCES usuarios(usuario_id) ON DELETE SET NULL
- FK `invitaciones_activacion_usuario_id_foreign`: FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE CASCADE
- PK `invitaciones_activacion_pkey`: PRIMARY KEY (invitacion_activacion_id)
- Único `invitaciones_activacion_hash_activacion_unique`: UNIQUE (hash_activacion)

**Índices**

- `invitaciones_activacion_emitida_por_usuario_id_index`: `btree (emitida_por_usuario_id)`
- `invitaciones_activacion_usuario_id_expira_en_index`: `btree (usuario_id, expira_en)`

## mensajes_ia

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `mensaje_ia_id` | UUID | no |  |
| `contenido_mensaje` | JSONB | no |  |
| `enviado_en` | TIMESTAMPTZ | no | `clock_timestamp()` |
| `silabo_id` | UUID | no |  |
| `usuario_id` | UUID | no |  |

**Restricciones**

- FK `mensajes_ia_silabo_id_foreign`: FOREIGN KEY (silabo_id) REFERENCES silabos(silabo_id) ON DELETE CASCADE
- FK `mensajes_ia_usuario_id_foreign`: FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- PK `mensajes_ia_pkey`: PRIMARY KEY (mensaje_ia_id)

**Checks**

- `mensajes_ia_contenido_mensaje_check`: CHECK (((jsonb_typeof(contenido_mensaje) = 'object'::text) AND (jsonb_typeof((contenido_mensaje -> 'entrada'::text)) = 'object'::text) AND ((contenido_mensaje ->> 'tipo'::text) = ANY (ARRAY['mensaje'::text, 'ocultamiento'::text])) AND ((contenido_mensaje ->> 'estado'::text) = ANY (ARRAY['pendiente'::text, 'en_ejecucion'::text, 'completado'::text, 'fallido'::text, 'registrada'::text]))))

**Índices**

- `mensajes_ia_conversacion_index`: `btree (silabo_id, usuario_id, enviado_en)`
- `mensajes_ia_mensaje_idempotencia_unica`: `btree (silabo_id, usuario_id, (((contenido_mensaje -> 'entrada'::text) ->> 'clave_idempotencia'::text))) WHERE (((contenido_mensaje ->> 'tipo'::text) = 'mensaje'::text) AND ((contenido_mensaje ->> 'rol'::text) = 'usuario'::text))`
- `mensajes_ia_usuario_id_index`: `btree (usuario_id)`

## migraciones

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `id` | INTEGER | no | `nextval('migraciones_id_seq'::regclass)` |
| `migration` | VARCHAR(255) | no |  |
| `batch` | INTEGER | no |  |

**Restricciones**

- PK `migraciones_pkey`: PRIMARY KEY (id)

## notificaciones

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `notificacion_id` | UUID | no |  |
| `usuario_id` | UUID | no |  |
| `clave_unica` | VARCHAR(180) | no |  |
| `tipo_notificacion` | VARCHAR(100) | no |  |
| `titulo_notificacion` | VARCHAR(180) | no |  |
| `mensaje_notificacion` | TEXT | no |  |
| `tipo_recurso` | VARCHAR(100) | sí |  |
| `recurso_id` | UUID | sí |  |
| `leido_en` | TIMESTAMPTZ | sí |  |
| `notificado_en` | TIMESTAMPTZ | no |  |

**Restricciones**

- FK `notificaciones_usuario_id_foreign`: FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- PK `notificaciones_pkey`: PRIMARY KEY (notificacion_id)
- Único `notificaciones_usuario_id_clave_unica_unique`: UNIQUE (usuario_id, clave_unica)

**Índices**

- `notificaciones_usuario_leido_notificado_index`: `btree (usuario_id, leido_en, notificado_en)`

## paralelos

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `paralelo_id` | UUID | no |  |
| `codigo_paralelo` | VARCHAR(30) | no |  |
| `jornada_paralelo` | VARCHAR(20) | no |  |
| `periodo_academico_id` | UUID | no |  |
| `asignatura_id` | UUID | no |  |

**Restricciones**

- FK `paralelos_asignatura_id_foreign`: FOREIGN KEY (asignatura_id) REFERENCES asignaturas(asignatura_id) ON DELETE RESTRICT
- FK `paralelos_periodo_academico_id_foreign`: FOREIGN KEY (periodo_academico_id) REFERENCES periodos_academicos(periodo_academico_id) ON DELETE RESTRICT
- PK `paralelos_pkey`: PRIMARY KEY (paralelo_id)
- Único `paralelos_periodo_asignatura_codigo_unique`: UNIQUE (periodo_academico_id, asignatura_id, codigo_paralelo)

**Checks**

- `paralelos_jornada_check`: CHECK (((jornada_paralelo)::text = ANY ((ARRAY['matutina'::character varying, 'vespertina'::character varying, 'nocturna'::character varying])::text[])))

**Índices**

- `paralelos_asignatura_id_index`: `btree (asignatura_id)`
- `paralelos_periodo_academico_id_index`: `btree (periodo_academico_id)`

## periodos_academicos

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `periodo_academico_id` | UUID | no |  |
| `codigo_periodo_academico` | VARCHAR(40) | no |  |
| `fecha_inicio_periodo` | DATE | no |  |
| `fecha_fin_periodo` | DATE | no |  |
| `cantidad_semanas_lectivas` | SMALLINT | no | `'16'::smallint` |

**Restricciones**

- PK `periodos_academicos_pkey`: PRIMARY KEY (periodo_academico_id)
- Único `periodos_academicos_codigo_unico`: UNIQUE (codigo_periodo_academico)

**Checks**

- `periodos_academicos_fechas_check`: CHECK ((fecha_fin_periodo >= fecha_inicio_periodo))
- `periodos_academicos_semanas_lectivas_rango`: CHECK (((cantidad_semanas_lectivas >= 1) AND (cantidad_semanas_lectivas <= 52)))

## plantilla_silabo

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `plantilla_id` | UUID | no |  |
| `mapeo_documento_plantilla` | JSONB | sí |  |
| `plantilla_modificada_en` | TIMESTAMPTZ | sí |  |

**Restricciones**

- PK `plantilla_silabo_pkey`: PRIMARY KEY (plantilla_id)

**Índices**

- `plantilla_silabo_unica`: `btree ((true))`

## requisitos_asignatura

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `requisito_asignatura_id` | UUID | no |  |
| `asignatura_id` | UUID | no |  |
| `requisito_id` | UUID | no |  |
| `tipo_requisito_asignatura` | VARCHAR(30) | no | `'prerrequisito'::character varying` |

**Restricciones**

- FK `requisitos_asignatura_asignatura_id_foreign`: FOREIGN KEY (asignatura_id) REFERENCES asignaturas(asignatura_id) ON DELETE RESTRICT
- FK `requisitos_asignatura_requisito_id_foreign`: FOREIGN KEY (requisito_id) REFERENCES asignaturas(asignatura_id) ON DELETE RESTRICT
- PK `requisitos_asignatura_pkey`: PRIMARY KEY (requisito_asignatura_id)
- Único `requisitos_asignatura_par_unico`: UNIQUE (asignatura_id, requisito_id)

**Checks**

- `requisitos_asignatura_distintos_check`: CHECK ((asignatura_id <> requisito_id))
- `requisitos_asignatura_tipo_check`: CHECK (((tipo_requisito_asignatura)::text = ANY ((ARRAY['prerrequisito'::character varying, 'correquisito'::character varying])::text[])))

**Índices**

- `requisitos_asignatura_requisito_id_index`: `btree (requisito_id)`

## restablecimientos_contrasena

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `email` | VARCHAR(255) | no |  |
| `token` | VARCHAR(255) | no |  |
| `created_at` | TIMESTAMPTZ | sí |  |

**Restricciones**

- PK `restablecimientos_contrasena_pkey`: PRIMARY KEY (email)

## revisiones_coordinacion

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `revision_coordinacion_id` | UUID | no |  |
| `revision_silabo_id` | UUID | no |  |
| `revisado_por` | UUID | no |  |
| `revisado_en` | TIMESTAMPTZ | no |  |

**Restricciones**

- FK `revisiones_coordinacion_revisado_por_fkey`: FOREIGN KEY (revisado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- FK `revisiones_coordinacion_revision_silabo_id_fkey`: FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT
- PK `revisiones_coordinacion_pkey`: PRIMARY KEY (revision_coordinacion_id)
- Único `revisiones_coordinacion_revision_unica`: UNIQUE (revision_silabo_id)

**Índices**

- `revisiones_coordinacion_revisado_por_index`: `btree (revisado_por)`

## revisiones_silabo

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `revision_silabo_id` | UUID | no |  |
| `silabo_id` | UUID | no |  |
| `revision_anterior_id` | UUID | sí |  |
| `numero_revision` | SMALLINT | no |  |
| `clave_solicitud` | UUID | no |  |
| `version_borrador` | INTEGER | no |  |
| `contenido_enviado` | JSONB | no |  |
| `hash_contenido` | CHAR(64) | no |  |
| `enviado_por` | UUID | no |  |
| `enviado_en` | TIMESTAMPTZ | no |  |
| `correccion_justificacion` | TEXT | sí |  |
| `correccion_etapa` | VARCHAR(16) | sí |  |
| `correccion_solicitada_por` | UUID | sí |  |
| `correccion_solicitada_en` | TIMESTAMPTZ | sí |  |

**Restricciones**

- FK `revisiones_silabo_correccion_solicitada_por_foreign`: FOREIGN KEY (correccion_solicitada_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- FK `revisiones_silabo_enviado_por_foreign`: FOREIGN KEY (enviado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT
- FK `revisiones_silabo_revision_anterior_id_foreign`: FOREIGN KEY (revision_anterior_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT
- FK `revisiones_silabo_silabo_id_foreign`: FOREIGN KEY (silabo_id) REFERENCES silabos(silabo_id) ON DELETE RESTRICT
- PK `revisiones_silabo_pkey`: PRIMARY KEY (revision_silabo_id)
- Único `revisiones_silabo_silabo_id_clave_solicitud_unique`: UNIQUE (silabo_id, clave_solicitud)
- Único `revisiones_silabo_silabo_id_numero_revision_unique`: UNIQUE (silabo_id, numero_revision)

**Checks**

- `revisiones_silabo_correccion_completa_check`: CHECK ((((correccion_justificacion IS NULL) AND (correccion_etapa IS NULL) AND (correccion_solicitada_por IS NULL) AND (correccion_solicitada_en IS NULL)) OR ((correccion_justificacion IS NOT NULL) AND (correccion_etapa IS NOT NULL) AND (correccion_solicitada_por IS NOT NULL) AND (correccion_solicitada_en IS NOT NULL))))
- `revisiones_silabo_correccion_etapa_check`: CHECK (((correccion_etapa)::text = ANY ((ARRAY['biblioteca'::character varying, 'coordinacion'::character varying, 'decanato'::character varying])::text[])))

**Índices**

- `revisiones_silabo_correccion_solicitada_por_index`: `btree (correccion_solicitada_por)`
- `revisiones_silabo_enviado_por_index`: `btree (enviado_por)`
- `revisiones_silabo_revision_anterior_id_index`: `btree (revision_anterior_id)`
- `revisiones_silabo_silabo_id_enviado_en_index`: `btree (silabo_id, enviado_en)`

## secciones_plantilla

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `seccion_plantilla_id` | UUID | no |  |
| `plantilla_id` | UUID | no |  |
| `clave_seccion_plantilla` | VARCHAR(120) | no |  |
| `titulo_seccion_plantilla` | VARCHAR(180) | no |  |
| `descripcion_seccion_plantilla` | TEXT | sí |  |
| `posicion_seccion_plantilla` | SMALLINT | no |  |
| `vigente_en_plantilla` | BOOLEAN | no | `true` |

**Restricciones**

- FK `secciones_plantilla_plantilla_id_foreign`: FOREIGN KEY (plantilla_id) REFERENCES plantilla_silabo(plantilla_id) ON DELETE CASCADE
- PK `secciones_plantilla_pkey`: PRIMARY KEY (seccion_plantilla_id)
- Único parcial `secciones_plantilla_vigente_clave_unica`: UNIQUE (plantilla_id, clave_seccion_plantilla) WHERE vigente_en_plantilla
- Único `secciones_plantilla_id_plantilla_unica`: UNIQUE (seccion_plantilla_id, plantilla_id)

**Índices**

- `secciones_plantilla_plantilla_id_posicion_index`: `btree (plantilla_id, posicion_seccion_plantilla)`

## sesiones

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `id` | VARCHAR(255) | no |  |
| `user_id` | UUID | sí |  |
| `ip_address` | VARCHAR(45) | sí |  |
| `user_agent` | TEXT | sí |  |
| `payload` | TEXT | no |  |
| `last_activity` | INTEGER | no |  |

**Restricciones**

- FK `sesiones_user_id_foreign`: FOREIGN KEY (user_id) REFERENCES usuarios(usuario_id) ON DELETE SET NULL
- PK `sesiones_pkey`: PRIMARY KEY (id)

**Índices**

- `sesiones_last_activity_index`: `btree (last_activity)`
- `sesiones_user_id_index`: `btree (user_id)`

## silabos

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `silabo_id` | UUID | no |  |
| `convocatoria_carrera_id` | UUID | no |  |
| `estado_silabo` | VARCHAR(32) | no | `'sin_iniciar'::character varying` |
| `version_borrador` | INTEGER | no | `0` |
| `iniciado_en` | TIMESTAMPTZ | sí |  |
| `guardado_en` | TIMESTAMPTZ | sí |  |
| `datos_academicos_silabo` | JSONB | no | `'{}'::jsonb` |
| `paralelo_id` | UUID | no |  |

**Restricciones**

- FK `silabos_convocatoria_carrera_id_foreign`: FOREIGN KEY (convocatoria_carrera_id) REFERENCES convocatorias_carreras(convocatoria_carrera_id) ON DELETE RESTRICT
- FK `silabos_paralelo_id_foreign`: FOREIGN KEY (paralelo_id) REFERENCES paralelos(paralelo_id) ON DELETE RESTRICT
- PK `silabos_pkey`: PRIMARY KEY (silabo_id)
- Único `silabos_convocatoria_id_paralelo_id_unique`: UNIQUE (convocatoria_carrera_id, paralelo_id)

**Checks**

- `silabos_estado_check`: CHECK (((estado_silabo)::text = ANY ((ARRAY['sin_iniciar'::character varying, 'borrador'::character varying, 'en_revision'::character varying, 'correccion_solicitada'::character varying, 'aprobado'::character varying])::text[])))

**Índices**

- `silabos_campania_id_estado_index`: `btree (convocatoria_carrera_id, estado_silabo)`
- `silabos_paralelo_id_index`: `btree (paralelo_id)`

## trabajos_en_cola

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `trabajo_id` | UUID | no |  |
| `tipo_trabajo` | VARCHAR(120) | no |  |
| `estado_trabajo` | VARCHAR(24) | no | `'pendiente'::character varying` |
| `clave_solicitud` | VARCHAR(160) | no |  |
| `intentos` | SMALLINT | no | `'0'::smallint` |
| `resultado_trabajo` | JSONB | sí |  |
| `mensaje_error` | TEXT | sí |  |
| `iniciado_en` | TIMESTAMPTZ | sí |  |
| `finalizado_en` | TIMESTAMPTZ | sí |  |
| `encolado_en` | TIMESTAMPTZ | sí |  |
| `cola` | VARCHAR(80) | no | `'general'::character varying` |
| `tipo_recurso` | VARCHAR(100) | sí |  |
| `recurso_id` | UUID | sí |  |
| `datos_entrada` | JSONB | sí |  |

**Restricciones**

- PK `trabajos_en_cola_pkey`: PRIMARY KEY (trabajo_id)
- Único `trabajos_en_cola_clave_solicitud_unique`: UNIQUE (clave_solicitud)

**Checks**

- `trabajos_en_cola_estado_trabajo_check`: CHECK (((estado_trabajo)::text = ANY ((ARRAY['pendiente'::character varying, 'en_ejecucion'::character varying, 'completada'::character varying, 'fallida'::character varying])::text[])))

**Índices**

- `trabajos_en_cola_cola_estado_trabajo_encolado_en_index`: `btree (cola, estado_trabajo, encolado_en)`
- `trabajos_en_cola_estado_trabajo_encolado_en_index`: `btree (estado_trabajo, encolado_en)`
- `trabajos_en_cola_tipo_recurso_recurso_id_index`: `btree (tipo_recurso, recurso_id)`

## usuarios

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `usuario_id` | UUID | no |  |
| `nombre_usuario` | VARCHAR(255) | no |  |
| `correo_electronico` | VARCHAR(255) | no |  |
| `correo_verificado_en` | TIMESTAMPTZ | sí |  |
| `contrasena` | VARCHAR(255) | no |  |
| `usuario_activo` | BOOLEAN | no | `true` |
| `codigo_recordarme` | VARCHAR(100) | sí |  |
| `secreto_dos_factores` | TEXT | sí |  |
| `codigos_recuperacion_dos_factores` | TEXT | sí |  |
| `dos_factores_confirmado_en` | TIMESTAMPTZ | sí |  |
| `formacion_experiencia_usuario` | TEXT | sí |  |

**Restricciones**

- PK `usuarios_pkey`: PRIMARY KEY (usuario_id)
- Único `usuarios_correo_electronico_unique`: UNIQUE (correo_electronico)

**Índices**

- `usuarios_usuario_activo_index`: `btree (usuario_activo)`

## valores_campo

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `valor_campo_id` | UUID | no |  |
| `silabo_id` | UUID | sí |  |
| `definicion_campo_id` | UUID | no |  |
| `valor_campo` | JSONB | sí |  |
| `asignatura_id` | UUID | sí |  |

**Restricciones**

- Check `valores_campo_un_solo_dueno_check`: CHECK ((silabo_id IS NULL) <> (asignatura_id IS NULL))
- FK `valores_campo_asignatura_id_foreign`: FOREIGN KEY (asignatura_id) REFERENCES asignaturas(asignatura_id) ON DELETE CASCADE
- FK `valores_campo_definicion_campo_id_foreign`: FOREIGN KEY (definicion_campo_id) REFERENCES definiciones_campo(definicion_campo_id) ON DELETE RESTRICT
- FK `valores_campo_silabo_id_foreign`: FOREIGN KEY (silabo_id) REFERENCES silabos(silabo_id) ON DELETE CASCADE
- PK `valores_campo_pkey`: PRIMARY KEY (valor_campo_id)

**Índices**

- `valores_campo_asignatura_definicion_unica`: único `(asignatura_id, definicion_campo_id)` donde asignatura_id no es nulo
- `valores_campo_silabo_definicion_unica`: único `(silabo_id, definicion_campo_id)` donde silabo_id no es nulo
- `valores_campo_definicion_campo_id_index`: `btree (definicion_campo_id)`
