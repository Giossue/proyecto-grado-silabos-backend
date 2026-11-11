# Esquema de la base de datos

Archivo generado. No lo edite a mano: vuelva a generarlo.

- Fecha: 2026-11-11.
- Última migración aplicada: `2026_11_11_000271_library_review_email_keeps_its_switch`.
- Origen: base temporal creada solo para regenerar este documento, con todas las migraciones aplicadas (PostgreSQL 18), esquema `public`, 37 tablas.
- Método: consultas de solo lectura a `pg_catalog` con `psql`:
  `psql --dbname=<base temporal> -X -At -f <consulta>.sql`.
  - columnas: `pg_attribute` + `format_type()` + `pg_attrdef` (`pg_get_expr`);
  - PK, FK y CHECK: `pg_constraint` + `pg_get_constraintdef()`;
  - únicos: `pg_index` con `indisunique` y sin `indisprimary` + `pg_get_indexdef()`;
  - triggers: `pg_trigger` sin `tgisinternal` + `pg_get_triggerdef()`;
  - vistas: `pg_get_viewdef()`; funciones propias: `pg_proc` sin las de extensiones.
- No incluye filas ni conteos de datos. Las restricciones `NOT NULL` se ven en la columna «Nulo».
- Los índices que no son únicos no se listan.
- Las consultas exactas están al final, en «Anexo: consultas».

Reglas y convenciones: [database.md](../architecture/database.md).

## Extensiones

- `btree_gist` 1.8

## Tipos enumerados

- `modalidad_estudio`: `Presencial`, `Semipresencial`, `En línea`, `A distancia`, `Híbrida`

## Vistas

### `docentes_vigentes_silabo`

```sql
SELECT silabos.silabo_id,
    asignaciones_rol.usuario_id,
    asignaciones_rol.asignacion_rol_id,
    asignaciones_paralelo.asignacion_paralelo_id,
    silabos.paralelo_id
   FROM silabos
     JOIN asignaciones_paralelo ON asignaciones_paralelo.paralelo_id = silabos.paralelo_id AND asignaciones_paralelo.asignacion_paralelo_activa
     JOIN asignaciones_rol ON asignaciones_rol.asignacion_rol_id = asignaciones_paralelo.asignacion_rol_id AND asignaciones_rol.asignacion_rol_activa AND asignaciones_rol.rol::text = 'docente'::text;
```

## Funciones propias

Funciones PL/pgSQL del esquema y las tablas cuyos triggers las llaman.

- `comprobar_administrador_exclusivo()`: triggers en `asignaciones_rol`
- `comprobar_coordinacion_activa_unica()`: triggers en `asignaciones_rol`
- `impedir_cambio_periodo_proceso_con_convocatorias()`: triggers en `convocatorias_universidad`
- `marcar_cambio_plantilla()`: triggers en `campos_plantilla`, `definiciones_campo`, `secciones_plantilla`
- `marcar_definicion_plantilla_modificada()`: triggers en `plantilla_silabo`
- `proteger_archivo_registrado()`: triggers en `archivos`
- `proteger_envio_biblioteca()`: triggers en `envios_biblioteca`
- `proteger_notificacion()`: triggers en `notificaciones`
- `proteger_registro_append_only()`: triggers en `aprobaciones`, `eventos_auditoria`, `firmas_silabo`, `revisiones_coordinacion`
- `proteger_registro_con_campos_unicos()`: triggers en `revisiones_silabo`
- `reinicio_institucional_habilitado()`: ningún trigger la llama directamente
- `validar_exportacion_silabo()`: triggers en `exportaciones_silabo`
- `validar_relaciones_flujo()`: triggers en `aprobaciones`
- `validar_relaciones_revision()`: triggers en `revisiones_silabo`

## Tablas

### `ajustes_sistema`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `clave_ajuste` | character varying(60) | no |  |
| `valor_ajuste` | jsonb | no |  |
| `modificado_por` | uuid | sí |  |
| `modificado_en` | timestamp(0) with time zone | no | `CURRENT_TIMESTAMP` |

- PK: `PRIMARY KEY (clave_ajuste)`
- FK:
  - `ajustes_sistema_modificado_por_foreign`: `FOREIGN KEY (modificado_por) REFERENCES usuarios(usuario_id) ON DELETE SET NULL`

### `aprobaciones`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `aprobacion_id` | uuid | no |  |
| `revision_silabo_id` | uuid | no |  |
| `clave_solicitud` | uuid | no |  |
| `hash_contenido` | character(64) | no |  |
| `aprobado_por` | uuid | no |  |
| `aprobado_en` | timestamp(0) with time zone | no |  |
| `aprobacion_anterior_id` | uuid | sí |  |

- PK: `PRIMARY KEY (aprobacion_id)`
- FK:
  - `aprobaciones_aprobacion_anterior_id_foreign`: `FOREIGN KEY (aprobacion_anterior_id) REFERENCES aprobaciones(aprobacion_id) ON DELETE RESTRICT`
  - `aprobaciones_aprobado_por_foreign`: `FOREIGN KEY (aprobado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
  - `aprobaciones_revision_silabo_id_foreign`: `FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT`
- Únicos:
  - `aprobaciones_revision_silabo_id_unique`: `(revision_silabo_id)`
- Triggers:
  - `proteger_aprobacion`: BEFORE DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()
  - `validar_aprobacion`: BEFORE INSERT OR UPDATE FOR EACH ROW EXECUTE FUNCTION validar_relaciones_flujo()

### `archivos`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `archivo_id` | uuid | no |  |
| `disco` | character varying(80) | no |  |
| `ruta_archivo` | character varying(500) | no |  |
| `nombre_archivo` | character varying(255) | no |  |
| `tipo_archivo` | character varying(120) | no |  |
| `tamano_bytes` | bigint | no |  |
| `hash_archivo` | character(64) | no |  |
| `categoria` | character varying(80) | no |  |
| `subido_por` | uuid | sí |  |
| `almacenado_en` | timestamp(0) with time zone | no |  |

- PK: `PRIMARY KEY (archivo_id)`
- FK:
  - `archivos_subido_por_foreign`: `FOREIGN KEY (subido_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
- Únicos:
  - `archivos_disco_ruta_archivo_unique`: `(disco, ruta_archivo)`
- Triggers:
  - `proteger_archivo`: BEFORE DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION proteger_archivo_registrado()

### `asignaciones_paralelo`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `asignacion_paralelo_id` | uuid | no |  |
| `paralelo_id` | uuid | no |  |
| `asignacion_paralelo_activa` | boolean | no | `true` |
| `asignado_en` | timestamp(0) with time zone | no | `CURRENT_TIMESTAMP` |
| `asignacion_rol_id` | uuid | no |  |

- PK: `PRIMARY KEY (asignacion_paralelo_id)`
- FK:
  - `asignaciones_paralelo_asignacion_rol_id_foreign`: `FOREIGN KEY (asignacion_rol_id) REFERENCES asignaciones_rol(asignacion_rol_id) ON DELETE RESTRICT`
  - `asignaciones_paralelo_paralelo_id_foreign`: `FOREIGN KEY (paralelo_id) REFERENCES paralelos(paralelo_id) ON DELETE RESTRICT`
- Únicos:
  - `asignaciones_paralelo_paralelo_activo_unico`: `(paralelo_id) WHERE asignacion_paralelo_activa`

### `asignaciones_rol`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `asignacion_rol_id` | uuid | no |  |
| `usuario_id` | uuid | no |  |
| `carrera_id` | uuid | sí |  |
| `asignacion_rol_activa` | boolean | no | `true` |
| `asignado_en` | timestamp(0) with time zone | no | `CURRENT_TIMESTAMP` |
| `rol` | character varying(20) | no |  |

- PK: `PRIMARY KEY (asignacion_rol_id)`
- FK:
  - `asignaciones_rol_carrera_id_foreign`: `FOREIGN KEY (carrera_id) REFERENCES carreras(carrera_id) ON DELETE RESTRICT`
  - `asignaciones_rol_usuario_id_foreign`: `FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
- Únicos:
  - `asignaciones_rol_activa_unica`: `(usuario_id, rol, COALESCE(carrera_id, '00000000-0000-0000-0000-000000000000'::uuid)) WHERE asignacion_rol_activa`
- CHECK:
  - `asignaciones_rol_alcance_valido_check`: `CHECK (((((rol)::text = ANY ((ARRAY['administrador'::character varying, 'bibliotecario'::character varying])::text[])) AND (carrera_id IS NULL)) OR (((rol)::text = ANY ((ARRAY['coordinador'::character varying, 'docente'::character varying])::text[])) AND (carrera_id IS NOT NULL))))`
  - `asignaciones_rol_rol_valido_check`: `CHECK (((rol)::text = ANY ((ARRAY['administrador'::character varying, 'coordinador'::character varying, 'docente'::character varying, 'bibliotecario'::character varying])::text[])))`
- Triggers:
  - `asignaciones_rol_administrador_exclusivo`: BEFORE INSERT OR UPDATE OF usuario_id, rol, asignacion_rol_activa FOR EACH ROW EXECUTE FUNCTION comprobar_administrador_exclusivo()
  - `asignaciones_rol_coordinacion_activa_unica`: BEFORE INSERT OR UPDATE OF asignacion_rol_activa, carrera_id, rol FOR EACH ROW EXECUTE FUNCTION comprobar_coordinacion_activa_unica()

### `asignaturas`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `asignatura_id` | uuid | no |  |
| `codigo_asignatura` | character varying(80) | no |  |
| `nombre_asignatura` | character varying(180) | no |  |
| `ciclo_asignatura` | smallint | sí |  |
| `creditos_asignatura` | numeric(6,2) | sí |  |
| `asignatura_activa` | boolean | no | `true` |
| `horas_docencia` | numeric(6,2) | sí |  |
| `horas_practicas` | numeric(6,2) | sí |  |
| `horas_autonomas` | numeric(6,2) | sí |  |
| `orden_asignatura_en_ciclo` | smallint | no | `'0'::smallint` |
| `unidad_curricular_asignatura` | character varying(30) | sí |  |
| `modalidad_asignatura` | modalidad_estudio | sí |  |
| `carrera_id` | uuid | no |  |
| `total_horas_asignatura` | smallint | sí | generada: `(round(((COALESCE(horas_docencia, (0)::numeric) + COALESCE(horas_practicas, (0)::numeric)) + COALESCE(horas_autonomas, (0)::numeric))))::smallint` |
| `genera_silabo_asignatura` | boolean | no | `true` |

- PK: `PRIMARY KEY (asignatura_id)`
- FK:
  - `asignaturas_carrera_id_foreign`: `FOREIGN KEY (carrera_id) REFERENCES carreras(carrera_id) ON DELETE RESTRICT`
- Únicos:
  - `asignaturas_carrera_id_codigo_asignatura_unique`: `(carrera_id, codigo_asignatura)`
  - `asignaturas_identidad_carrera_unica`: `(asignatura_id, carrera_id)`
- CHECK:
  - `asignaturas_unidad_curricular_check`: `CHECK (((unidad_curricular_asignatura)::text = ANY ((ARRAY['basica'::character varying, 'profesional'::character varying, 'integracion_curricular'::character varying])::text[])))`

### `campos_plantilla`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `campo_plantilla_id` | uuid | no |  |
| `seccion_plantilla_id` | uuid | no |  |
| `plantilla_id` | uuid | no |  |
| `clave_campo_plantilla` | character varying(120) | no |  |
| `titulo_campo_plantilla` | character varying(180) | no | `''::character varying` |
| `posicion_campo_plantilla` | smallint | no |  |
| `vigente_en_plantilla` | boolean | no | `true` |

- PK: `PRIMARY KEY (campo_plantilla_id)`
- FK:
  - `campos_plantilla_plantilla_id_foreign`: `FOREIGN KEY (plantilla_id) REFERENCES plantilla_silabo(plantilla_id) ON DELETE CASCADE`
  - `campos_plantilla_seccion_misma_plantilla_foreign`: `FOREIGN KEY (seccion_plantilla_id, plantilla_id) REFERENCES secciones_plantilla(seccion_plantilla_id, plantilla_id) ON DELETE RESTRICT`
  - `campos_plantilla_seccion_plantilla_id_foreign`: `FOREIGN KEY (seccion_plantilla_id) REFERENCES secciones_plantilla(seccion_plantilla_id) ON DELETE CASCADE`
- Únicos:
  - `campos_plantilla_id_plantilla_unica`: `(campo_plantilla_id, plantilla_id)`
  - `campos_plantilla_vigente_clave_unica`: `(plantilla_id, clave_campo_plantilla) WHERE vigente_en_plantilla`
- Triggers:
  - `marcar_cambio_campo_plantilla`: AFTER INSERT OR DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION marcar_cambio_plantilla()

### `carreras`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `carrera_id` | uuid | no |  |
| `facultad_id` | uuid | no |  |
| `codigo_carrera` | character varying(80) | no |  |
| `nombre_carrera` | character varying(180) | no |  |
| `carrera_activa` | boolean | no | `true` |
| `modalidad_carrera` | modalidad_estudio | sí |  |
| `numero_ciclos` | smallint | no |  |

- PK: `PRIMARY KEY (carrera_id)`
- FK:
  - `carreras_facultad_id_foreign`: `FOREIGN KEY (facultad_id) REFERENCES facultades(facultad_id) ON DELETE RESTRICT`
- Únicos:
  - `carreras_codigo_carrera_unique`: `(codigo_carrera)`
- CHECK:
  - `carreras_numero_ciclos_check`: `CHECK (((numero_ciclos >= 1) AND (numero_ciclos <= 30)))`

### `convocatorias_carreras`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `convocatoria_carrera_id` | uuid | no |  |
| `carrera_id` | uuid | no |  |
| `estado_convocatoria_carrera` | character varying(24) | no | `'preparacion'::character varying` |
| `convocatoria_universidad_id` | uuid | no |  |
| `estado_previo_cierre_convocatoria_carrera` | character varying(24) | sí |  |

- PK: `PRIMARY KEY (convocatoria_carrera_id)`
- FK:
  - `convocatorias_carreras_carrera_id_foreign`: `FOREIGN KEY (carrera_id) REFERENCES carreras(carrera_id) ON DELETE RESTRICT`
  - `convocatorias_carreras_convocatoria_universidad_id_foreign`: `FOREIGN KEY (convocatoria_universidad_id) REFERENCES convocatorias_universidad(convocatoria_universidad_id) ON DELETE RESTRICT`
- Únicos:
  - `convocatorias_carreras_carrera_proceso_unico`: `(carrera_id, convocatoria_universidad_id)`
- CHECK:
  - `convocatorias_carreras_estado_check`: `CHECK (((estado_convocatoria_carrera)::text = ANY ((ARRAY['preparacion'::character varying, 'abierta'::character varying, 'pausada'::character varying, 'cerrada'::character varying])::text[])))`
  - `convocatorias_carreras_estado_previo_cierre_check`: `CHECK (((estado_previo_cierre_convocatoria_carrera IS NULL) OR (((estado_convocatoria_carrera)::text = 'cerrada'::text) AND ((estado_previo_cierre_convocatoria_carrera)::text = ANY ((ARRAY['abierta'::character varying, 'pausada'::character varying])::text[])))))`

### `convocatorias_universidad`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `convocatoria_universidad_id` | uuid | no |  |
| `fecha_inicio` | timestamp(0) with time zone | no |  |
| `fecha_entrega` | timestamp(0) with time zone | no |  |
| `estado_convocatoria_universidad` | character varying(20) | no | `'abierto'::character varying` |
| `periodo_academico_id` | uuid | no |  |
| `pausado_en` | timestamp(6) with time zone | sí |  |
| `cantidad_prorrogas` | integer | no | `0` |
| `finalizada_en` | timestamp(6) with time zone | sí |  |

- PK: `PRIMARY KEY (convocatoria_universidad_id)`
- FK:
  - `convocatorias_universidad_periodo_academico_id_foreign`: `FOREIGN KEY (periodo_academico_id) REFERENCES periodos_academicos(periodo_academico_id) ON DELETE RESTRICT`
- Únicos:
  - `convocatorias_universidad_en_curso_unico`: `((1)) WHERE ((estado_convocatoria_universidad)::text = ANY ((ARRAY['abierto'::character varying, 'pausado'::character varying])::text[]))`
  - `convocatorias_universidad_periodo_unico`: `(periodo_academico_id)`
- CHECK:
  - `convocatorias_universidad_cantidad_prorrogas_check`: `CHECK ((cantidad_prorrogas >= 0))`
  - `convocatorias_universidad_estado_check`: `CHECK (((estado_convocatoria_universidad)::text = ANY ((ARRAY['abierto'::character varying, 'pausado'::character varying, 'cerrado'::character varying])::text[])))`
  - `convocatorias_universidad_fechas_check`: `CHECK ((fecha_entrega > fecha_inicio))`
  - `convocatorias_universidad_finalizada_en_check`: `CHECK (((finalizada_en IS NULL) OR ((estado_convocatoria_universidad)::text = 'cerrado'::text)))`
- Triggers:
  - `convocatorias_universidad_periodo_protegido`: BEFORE UPDATE OF periodo_academico_id FOR EACH ROW EXECUTE FUNCTION impedir_cambio_periodo_proceso_con_convocatorias()

### `definiciones_campo`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `definicion_campo_id` | uuid | no |  |
| `campo_plantilla_id` | uuid | no |  |
| `plantilla_id` | uuid | no |  |
| `vigente_en_plantilla` | boolean | no | `true` |
| `quien_llena_definicion_campo` | character varying(20) | no | `'carrera'::character varying` |
| `contenido_definicion_campo` | jsonb | no | `'{"type": "doc", "content": [{"type": "paragraph"}]}'::jsonb` |

- PK: `PRIMARY KEY (definicion_campo_id)`
- FK:
  - `definiciones_campo_campo_misma_plantilla_foreign`: `FOREIGN KEY (campo_plantilla_id, plantilla_id) REFERENCES campos_plantilla(campo_plantilla_id, plantilla_id) ON DELETE RESTRICT`
  - `definiciones_campo_campo_plantilla_id_foreign`: `FOREIGN KEY (campo_plantilla_id) REFERENCES campos_plantilla(campo_plantilla_id) ON DELETE CASCADE`
  - `definiciones_campo_plantilla_id_foreign`: `FOREIGN KEY (plantilla_id) REFERENCES plantilla_silabo(plantilla_id) ON DELETE CASCADE`
- Únicos:
  - `definiciones_campo_campo_unico`: `(campo_plantilla_id)`
- CHECK:
  - `definiciones_campo_documento_check`: `CHECK (((contenido_definicion_campo ? 'type'::text) AND (contenido_definicion_campo ? 'content'::text) AND (jsonb_typeof(contenido_definicion_campo) = 'object'::text) AND ((contenido_definicion_campo ->> 'type'::text) = 'doc'::text) AND (jsonb_typeof((contenido_definicion_campo -> 'content'::text)) = 'array'::text)))`
  - `definiciones_campo_responsable_check`: `CHECK (((quien_llena_definicion_campo)::text = ANY ((ARRAY['carrera'::character varying, 'institucional'::character varying])::text[])))`
- Triggers:
  - `marcar_cambio_definicion_campo_plantilla`: AFTER INSERT OR DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION marcar_cambio_plantilla()

### `documentos_apoyo`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `documento_apoyo_id` | uuid | no |  |
| `carrera_id` | uuid | no |  |
| `titulo_documento` | character varying(180) | no |  |
| `archivo_id` | uuid | no |  |
| `archivo_vista_id` | uuid | sí |  |
| `estado_vista` | character varying(20) | no | `'pendiente'::character varying` |
| `subido_por` | uuid | no |  |
| `subido_en` | timestamp(0) with time zone | no | `CURRENT_TIMESTAMP` |

- PK: `PRIMARY KEY (documento_apoyo_id)`
- FK:
  - `documentos_apoyo_archivo_id_foreign`: `FOREIGN KEY (archivo_id) REFERENCES archivos(archivo_id) ON DELETE RESTRICT`
  - `documentos_apoyo_archivo_vista_id_foreign`: `FOREIGN KEY (archivo_vista_id) REFERENCES archivos(archivo_id) ON DELETE RESTRICT`
  - `documentos_apoyo_carrera_id_foreign`: `FOREIGN KEY (carrera_id) REFERENCES carreras(carrera_id) ON DELETE RESTRICT`
  - `documentos_apoyo_subido_por_foreign`: `FOREIGN KEY (subido_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
- Únicos:
  - `documentos_apoyo_archivo_id_unique`: `(archivo_id)`
- CHECK:
  - `documentos_apoyo_estado_vista_check`: `CHECK (((estado_vista)::text = ANY ((ARRAY['pendiente'::character varying, 'lista'::character varying, 'sin_vista'::character varying])::text[])))`
  - `documentos_apoyo_titulo_check`: `CHECK ((btrim((titulo_documento)::text) <> ''::text))`
  - `documentos_apoyo_vista_check`: `CHECK ((((estado_vista)::text <> 'lista'::text) OR (archivo_vista_id IS NOT NULL)))`

### `envios_biblioteca`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `envio_biblioteca_id` | uuid | no |  |
| `revision_silabo_id` | uuid | no |  |
| `verificado_por` | uuid | sí |  |
| `verificado_en` | timestamp with time zone | sí |  |
| `verificacion_heredada` | boolean | no | `false` |

- PK: `PRIMARY KEY (envio_biblioteca_id)`
- FK:
  - `envios_biblioteca_revision_silabo_id_foreign`: `FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT`
  - `envios_biblioteca_verificado_por_fkey`: `FOREIGN KEY (verificado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
- Únicos:
  - `envios_biblioteca_revision_unica`: `(revision_silabo_id)`
- CHECK:
  - `envios_biblioteca_herencia_verificada`: `CHECK (((NOT verificacion_heredada) OR (verificado_en IS NOT NULL)))`
  - `envios_biblioteca_verificacion_completa`: `CHECK (((verificado_por IS NULL) = (verificado_en IS NULL)))`
- Triggers:
  - `proteger_envio_biblioteca`: BEFORE DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION proteger_envio_biblioteca()

### `eventos_auditoria`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `evento_auditoria_id` | uuid | no |  |
| `usuario_id` | uuid | sí |  |
| `asignacion_rol_id` | uuid | sí |  |
| `accion` | character varying(120) | no |  |
| `tipo_recurso` | character varying(120) | no |  |
| `recurso_id` | uuid | sí |  |
| `resultado` | character varying(24) | no |  |
| `detalles` | jsonb | sí |  |
| `ocurrido_en` | timestamp(0) with time zone | no |  |
| `actor_nombre` | character varying(255) | sí |  |
| `actor_rol` | character varying(120) | sí |  |
| `actor_carrera` | character varying(255) | sí |  |

- PK: `PRIMARY KEY (evento_auditoria_id)`
- CHECK:
  - `eventos_auditoria_resultado_check`: `CHECK (((resultado)::text = ANY ((ARRAY['exito'::character varying, 'fallido'::character varying])::text[])))`
- Triggers:
  - `proteger_evento_auditoria_db`: BEFORE DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()

### `exportaciones_silabo`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `exportacion_silabo_id` | uuid | no |  |
| `revision_silabo_id` | uuid | no |  |
| `archivo_pdf_id` | uuid | sí |  |
| `clave_solicitud` | uuid | no |  |
| `estado_exportacion` | character varying(24) | no | `'pendiente'::character varying` |
| `solicitado_por` | uuid | no |  |
| `solicitado_en` | timestamp(0) with time zone | no |  |
| `completado_en` | timestamp(0) with time zone | sí |  |

- PK: `PRIMARY KEY (exportacion_silabo_id)`
- FK:
  - `exportaciones_silabo_archivo_pdf_id_foreign`: `FOREIGN KEY (archivo_pdf_id) REFERENCES archivos(archivo_id) ON DELETE RESTRICT`
  - `exportaciones_silabo_revision_silabo_id_foreign`: `FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT`
  - `exportaciones_silabo_solicitado_por_foreign`: `FOREIGN KEY (solicitado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
- Únicos:
  - `exportaciones_silabo_archivo_pdf_id_unique`: `(archivo_pdf_id)`
  - `exportaciones_silabo_revision_clave_solicitud_unica`: `(revision_silabo_id, clave_solicitud)`
- CHECK:
  - `exportaciones_silabo_completitud_check`: `CHECK ((((estado_exportacion)::text <> 'completado'::text) OR ((archivo_pdf_id IS NOT NULL) AND (completado_en IS NOT NULL))))`
  - `exportaciones_silabo_estado_check`: `CHECK (((estado_exportacion)::text = ANY ((ARRAY['pendiente'::character varying, 'en_ejecucion'::character varying, 'completado'::character varying, 'fallido'::character varying])::text[])))`
- Triggers:
  - `validar_exportacion_silabo`: BEFORE INSERT OR DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION validar_exportacion_silabo()

### `facultades`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `facultad_id` | uuid | no |  |
| `nombre_facultad` | character varying(180) | no |  |
| `facultad_activa` | boolean | no | `true` |
| `logo_archivo_id` | uuid | sí |  |
| `campus_facultad` | character varying(255) | no |  |
| `bibliotecas_koha_facultad` | jsonb | no | `'[]'::jsonb` |

- PK: `PRIMARY KEY (facultad_id)`
- FK:
  - `facultades_logo_archivo_id_foreign`: `FOREIGN KEY (logo_archivo_id) REFERENCES archivos(archivo_id) ON DELETE RESTRICT`
- CHECK:
  - `facultades_bibliotecas_koha_lista_check`: `CHECK ((jsonb_typeof(bibliotecas_koha_facultad) = 'array'::text))`
  - `facultades_campus_facultad_check`: `CHECK (((campus_facultad)::text = ANY ((ARRAY['Campus Matriz Dr. Gabriel Galarza López'::character varying, 'Campus Laguacoto'::character varying, 'Campus CENI (Centro de Eventos Nacionales e Internacionales)'::character varying, 'Extensión San Miguel'::character varying, 'Sede Chimbo'::character varying])::text[])))`

### `firmas_silabo`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `firma_silabo_id` | uuid | no |  |
| `silabo_id` | uuid | no |  |
| `revision_silabo_id` | uuid | no |  |
| `firmado_como` | character varying(16) | no |  |
| `usuario_id` | uuid | no |  |
| `declaracion_firmada` | text | no |  |
| `firma_cms` | text | no |  |
| `nombre_en_certificado` | character varying(255) | no |  |
| `numero_certificado` | character varying(255) | no |  |
| `entidad_emisora` | character varying(500) | no |  |
| `certificado_valido_hasta` | timestamp(0) with time zone | no |  |
| `firmado_en` | timestamp(0) with time zone | no |  |

- PK: `PRIMARY KEY (firma_silabo_id)`
- FK:
  - `firmas_silabo_revision_silabo_id_foreign`: `FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT`
  - `firmas_silabo_silabo_id_foreign`: `FOREIGN KEY (silabo_id) REFERENCES silabos(silabo_id) ON DELETE RESTRICT`
  - `firmas_silabo_usuario_id_foreign`: `FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
- Únicos:
  - `firmas_silabo_firmado_como_unico`: `(silabo_id, firmado_como)`
- CHECK:
  - `firmas_silabo_firmado_como_check`: `CHECK (((firmado_como)::text = ANY ((ARRAY['docente'::character varying, 'coordinacion'::character varying, 'biblioteca'::character varying])::text[])))`
- Triggers:
  - `proteger_firma_silabo`: BEFORE DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()

### `invitaciones_activacion`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `invitacion_activacion_id` | uuid | no |  |
| `usuario_id` | uuid | no |  |
| `hash_activacion` | character(64) | no |  |
| `expira_en` | timestamp(0) with time zone | no |  |
| `usada_en` | timestamp(0) with time zone | sí |  |
| `invalidada_en` | timestamp(0) with time zone | sí |  |
| `emitida_por_usuario_id` | uuid | sí |  |
| `creada_en` | timestamp(0) with time zone | no | `CURRENT_TIMESTAMP` |

- PK: `PRIMARY KEY (invitacion_activacion_id)`
- FK:
  - `invitaciones_activacion_emitida_por_usuario_id_foreign`: `FOREIGN KEY (emitida_por_usuario_id) REFERENCES usuarios(usuario_id) ON DELETE SET NULL`
  - `invitaciones_activacion_usuario_id_foreign`: `FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE CASCADE`
- Únicos:
  - `invitaciones_activacion_hash_activacion_unique`: `(hash_activacion)`

### `mensajes_ia_plantilla`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `mensaje_ia_plantilla_id` | uuid | no |  |
| `plantilla_id` | uuid | no |  |
| `usuario_id` | uuid | no |  |
| `contenido_mensaje` | jsonb | no |  |
| `enviado_en` | timestamp(6) with time zone | no | `clock_timestamp()` |
| `ocultado_en` | timestamp(6) with time zone | sí |  |

- PK: `PRIMARY KEY (mensaje_ia_plantilla_id)`
- FK:
  - `mensajes_ia_plantilla_plantilla_id_foreign`: `FOREIGN KEY (plantilla_id) REFERENCES plantilla_silabo(plantilla_id) ON DELETE CASCADE`
  - `mensajes_ia_plantilla_usuario_id_foreign`: `FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
- Únicos:
  - `mensajes_ia_plantilla_idempotencia_unica`: `(plantilla_id, usuario_id, (((contenido_mensaje -> 'entrada'::text) ->> 'clave_idempotencia'::text))) WHERE ((contenido_mensaje ->> 'rol'::text) = 'usuario'::text)`
- CHECK:
  - `mensajes_ia_plantilla_contenido_check`: `CHECK (((jsonb_typeof(contenido_mensaje) = 'object'::text) AND ((contenido_mensaje ->> 'rol'::text) = ANY (ARRAY['usuario'::text, 'asistente'::text])) AND ((contenido_mensaje ->> 'estado'::text) = ANY (ARRAY['pendiente'::text, 'en_ejecucion'::text, 'completado'::text, 'fallido'::text]))))`

### `notificaciones`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `notificacion_id` | uuid | no |  |
| `usuario_id` | uuid | no |  |
| `clave_unica` | character varying(180) | no |  |
| `tipo_notificacion` | character varying(100) | no |  |
| `titulo_notificacion` | character varying(180) | no |  |
| `mensaje_notificacion` | text | no |  |
| `tipo_recurso` | character varying(100) | sí |  |
| `recurso_id` | uuid | sí |  |
| `leido_en` | timestamp(0) with time zone | sí |  |
| `notificado_en` | timestamp(0) with time zone | no |  |
| `periodo_academico_id` | uuid | sí |  |

- PK: `PRIMARY KEY (notificacion_id)`
- FK:
  - `notificaciones_periodo_academico_id_foreign`: `FOREIGN KEY (periodo_academico_id) REFERENCES periodos_academicos(periodo_academico_id) ON DELETE SET NULL`
  - `notificaciones_usuario_id_foreign`: `FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
- Únicos:
  - `notificaciones_usuario_id_clave_unica_unique`: `(usuario_id, clave_unica)`
- Triggers:
  - `proteger_notificacion`: BEFORE DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION proteger_notificacion()

### `paralelos`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `paralelo_id` | uuid | no |  |
| `codigo_paralelo` | character varying(30) | no |  |
| `jornada_paralelo` | character varying(20) | no |  |
| `asignatura_id` | uuid | no |  |

- PK: `PRIMARY KEY (paralelo_id)`
- FK:
  - `paralelos_asignatura_id_foreign`: `FOREIGN KEY (asignatura_id) REFERENCES asignaturas(asignatura_id) ON DELETE RESTRICT`
- Únicos:
  - `paralelos_asignatura_codigo_unique`: `(asignatura_id, upper((codigo_paralelo)::text))`
- CHECK:
  - `paralelos_jornada_check`: `CHECK (((jornada_paralelo)::text = ANY ((ARRAY['matutina'::character varying, 'vespertina'::character varying, 'nocturna'::character varying])::text[])))`

### `periodos_academicos`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `periodo_academico_id` | uuid | no |  |
| `codigo_periodo_academico` | character varying(40) | no |  |
| `fecha_inicio_periodo` | date | no |  |
| `fecha_fin_periodo` | date | no |  |
| `cantidad_semanas_lectivas` | smallint | no | `'16'::smallint` |

- PK: `PRIMARY KEY (periodo_academico_id)`
- Únicos:
  - `periodos_academicos_codigo_unico`: `(codigo_periodo_academico)`
- CHECK:
  - `periodos_academicos_fechas_check`: `CHECK ((fecha_fin_periodo >= fecha_inicio_periodo))`
  - `periodos_academicos_semanas_lectivas_rango`: `CHECK (((cantidad_semanas_lectivas >= 1) AND (cantidad_semanas_lectivas <= 52)))`

### `plantilla_silabo`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `plantilla_id` | uuid | no |  |
| `plantilla_modificada_en` | timestamp(6) with time zone | sí |  |
| `definicion_plantilla` | json | no | `'{}'::json` |

- PK: `PRIMARY KEY (plantilla_id)`
- Únicos:
  - `plantilla_silabo_unica`: `((true))`
- CHECK:
  - `definicion_plantilla_objeto`: `CHECK ((json_typeof(definicion_plantilla) = 'object'::text))`
- Triggers:
  - `marcar_definicion_plantilla_modificada`: BEFORE UPDATE OF definicion_plantilla FOR EACH ROW EXECUTE FUNCTION marcar_definicion_plantilla_modificada()

### `propuestas_ia_plantilla`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `propuesta_ia_plantilla_id` | uuid | no |  |
| `plantilla_id` | uuid | no |  |
| `mensaje_ia_plantilla_id` | uuid | no |  |
| `solicitada_por` | uuid | no |  |
| `estructura_propuesta` | jsonb | no |  |
| `cambios_propuesta` | jsonb | no |  |
| `huella_plantilla_base` | character(64) | no |  |
| `version_borrador_propuesta` | character varying(100) | no |  |
| `estado_propuesta` | character varying(20) | no | `'pendiente'::character varying` |
| `decidida_por` | uuid | sí |  |
| `decidida_en` | timestamp(6) with time zone | sí |  |
| `creada_en` | timestamp(6) with time zone | no | `clock_timestamp()` |

- PK: `PRIMARY KEY (propuesta_ia_plantilla_id)`
- FK:
  - `propuestas_ia_plantilla_decidida_por_foreign`: `FOREIGN KEY (decidida_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
  - `propuestas_ia_plantilla_mensaje_ia_plantilla_id_foreign`: `FOREIGN KEY (mensaje_ia_plantilla_id) REFERENCES mensajes_ia_plantilla(mensaje_ia_plantilla_id) ON DELETE CASCADE`
  - `propuestas_ia_plantilla_plantilla_id_foreign`: `FOREIGN KEY (plantilla_id) REFERENCES plantilla_silabo(plantilla_id) ON DELETE CASCADE`
  - `propuestas_ia_plantilla_solicitada_por_foreign`: `FOREIGN KEY (solicitada_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
- Únicos:
  - `propuestas_ia_plantilla_mensaje_ia_plantilla_id_unique`: `(mensaje_ia_plantilla_id)`
- CHECK:
  - `propuestas_ia_plantilla_decision_check`: `CHECK ((((estado_propuesta)::text <> ALL ((ARRAY['aplicada'::character varying, 'descartada'::character varying])::text[])) OR ((decidida_por IS NOT NULL) AND (decidida_en IS NOT NULL))))`
  - `propuestas_ia_plantilla_estado_check`: `CHECK (((estado_propuesta)::text = ANY ((ARRAY['pendiente'::character varying, 'aplicada'::character varying, 'descartada'::character varying, 'vencida'::character varying, 'reemplazada'::character varying])::text[])))`
  - `propuestas_ia_plantilla_huella_check`: `CHECK ((huella_plantilla_base ~ '^[0-9a-f]{64}$'::text))`

### `requisitos_asignatura`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `requisito_asignatura_id` | uuid | no |  |
| `asignatura_id` | uuid | no |  |
| `requisito_id` | uuid | no |  |
| `tipo_requisito_asignatura` | character varying(30) | no | `'prerrequisito'::character varying` |

- PK: `PRIMARY KEY (requisito_asignatura_id)`
- FK:
  - `requisitos_asignatura_asignatura_id_foreign`: `FOREIGN KEY (asignatura_id) REFERENCES asignaturas(asignatura_id) ON DELETE RESTRICT`
  - `requisitos_asignatura_requisito_id_foreign`: `FOREIGN KEY (requisito_id) REFERENCES asignaturas(asignatura_id) ON DELETE RESTRICT`
- Únicos:
  - `requisitos_asignatura_par_unico`: `(asignatura_id, requisito_id)`
- CHECK:
  - `requisitos_asignatura_distintos_check`: `CHECK ((asignatura_id <> requisito_id))`
  - `requisitos_asignatura_tipo_check`: `CHECK (((tipo_requisito_asignatura)::text = ANY ((ARRAY['prerrequisito'::character varying, 'correquisito'::character varying])::text[])))`

### `revisiones_coordinacion`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `revision_coordinacion_id` | uuid | no |  |
| `revision_silabo_id` | uuid | no |  |
| `revisado_por` | uuid | no |  |
| `revisado_en` | timestamp with time zone | no |  |

- PK: `PRIMARY KEY (revision_coordinacion_id)`
- FK:
  - `revisiones_coordinacion_revisado_por_fkey`: `FOREIGN KEY (revisado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
  - `revisiones_coordinacion_revision_silabo_id_fkey`: `FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT`
- Únicos:
  - `revisiones_coordinacion_revision_unica`: `(revision_silabo_id)`
- Triggers:
  - `proteger_revision_coordinacion`: BEFORE DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()

### `revisiones_silabo`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `revision_silabo_id` | uuid | no |  |
| `silabo_id` | uuid | no |  |
| `revision_anterior_id` | uuid | sí |  |
| `numero_revision` | smallint | no |  |
| `clave_solicitud` | uuid | no |  |
| `version_borrador` | integer | no |  |
| `contenido_enviado` | jsonb | no |  |
| `hash_contenido` | character(64) | no |  |
| `enviado_por` | uuid | no |  |
| `enviado_en` | timestamp(0) with time zone | no |  |
| `correccion_justificacion` | text | sí |  |
| `correccion_etapa` | character varying(16) | sí |  |
| `correccion_solicitada_por` | uuid | sí |  |
| `correccion_solicitada_en` | timestamp with time zone | sí |  |

- PK: `PRIMARY KEY (revision_silabo_id)`
- FK:
  - `revisiones_silabo_correccion_solicitada_por_foreign`: `FOREIGN KEY (correccion_solicitada_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
  - `revisiones_silabo_enviado_por_foreign`: `FOREIGN KEY (enviado_por) REFERENCES usuarios(usuario_id) ON DELETE RESTRICT`
  - `revisiones_silabo_revision_anterior_id_foreign`: `FOREIGN KEY (revision_anterior_id) REFERENCES revisiones_silabo(revision_silabo_id) ON DELETE RESTRICT`
  - `revisiones_silabo_silabo_id_foreign`: `FOREIGN KEY (silabo_id) REFERENCES silabos(silabo_id) ON DELETE RESTRICT`
- Únicos:
  - `revisiones_silabo_silabo_id_clave_solicitud_unique`: `(silabo_id, clave_solicitud)`
  - `revisiones_silabo_silabo_id_numero_revision_unique`: `(silabo_id, numero_revision)`
- CHECK:
  - `revisiones_silabo_correccion_completa_check`: `CHECK ((((correccion_justificacion IS NULL) AND (correccion_etapa IS NULL) AND (correccion_solicitada_por IS NULL) AND (correccion_solicitada_en IS NULL)) OR ((correccion_justificacion IS NOT NULL) AND (correccion_etapa IS NOT NULL) AND (correccion_solicitada_por IS NOT NULL) AND (correccion_solicitada_en IS NOT NULL))))`
  - `revisiones_silabo_correccion_etapa_check`: `CHECK (((correccion_etapa)::text = ANY ((ARRAY['biblioteca'::character varying, 'coordinacion'::character varying])::text[])))`
- Triggers:
  - `proteger_revision_silabo`: BEFORE DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION proteger_registro_con_campos_unicos('correccion_justificacion', 'correccion_etapa', 'correccion_solicitada_por', 'correccion_solicitada_en')
  - `validar_revision_silabo`: BEFORE INSERT OR UPDATE FOR EACH ROW EXECUTE FUNCTION validar_relaciones_revision()

### `secciones_plantilla`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `seccion_plantilla_id` | uuid | no |  |
| `plantilla_id` | uuid | no |  |
| `clave_seccion_plantilla` | character varying(120) | no |  |
| `titulo_seccion_plantilla` | character varying(180) | no |  |
| `posicion_seccion_plantilla` | smallint | no |  |
| `vigente_en_plantilla` | boolean | no | `true` |
| `orientacion_seccion_plantilla` | character varying(12) | no | `'portrait'::character varying` |

- PK: `PRIMARY KEY (seccion_plantilla_id)`
- FK:
  - `secciones_plantilla_plantilla_id_foreign`: `FOREIGN KEY (plantilla_id) REFERENCES plantilla_silabo(plantilla_id) ON DELETE CASCADE`
- Únicos:
  - `secciones_plantilla_id_plantilla_unica`: `(seccion_plantilla_id, plantilla_id)`
  - `secciones_plantilla_vigente_clave_unica`: `(plantilla_id, clave_seccion_plantilla) WHERE vigente_en_plantilla`
- CHECK:
  - `secciones_plantilla_orientacion_check`: `CHECK (((orientacion_seccion_plantilla)::text = ANY ((ARRAY['portrait'::character varying, 'landscape'::character varying])::text[])))`
- Triggers:
  - `marcar_cambio_seccion_plantilla`: AFTER INSERT OR DELETE OR UPDATE FOR EACH ROW EXECUTE FUNCTION marcar_cambio_plantilla()

### `silabos`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `silabo_id` | uuid | no |  |
| `convocatoria_carrera_id` | uuid | no |  |
| `estado_silabo` | character varying(32) | no | `'sin_iniciar'::character varying` |
| `version_borrador` | integer | no | `0` |
| `iniciado_en` | timestamp(0) with time zone | sí |  |
| `guardado_en` | timestamp(0) with time zone | sí |  |
| `datos_academicos_silabo` | jsonb | no | `'{}'::jsonb` |
| `paralelo_id` | uuid | no |  |

- PK: `PRIMARY KEY (silabo_id)`
- FK:
  - `silabos_convocatoria_carrera_id_foreign`: `FOREIGN KEY (convocatoria_carrera_id) REFERENCES convocatorias_carreras(convocatoria_carrera_id) ON DELETE RESTRICT`
  - `silabos_paralelo_id_foreign`: `FOREIGN KEY (paralelo_id) REFERENCES paralelos(paralelo_id) ON DELETE RESTRICT`
- Únicos:
  - `silabos_convocatoria_id_paralelo_id_unique`: `(convocatoria_carrera_id, paralelo_id)`
- CHECK:
  - `silabos_estado_check`: `CHECK (((estado_silabo)::text = ANY ((ARRAY['sin_iniciar'::character varying, 'borrador'::character varying, 'en_revision'::character varying, 'correccion_solicitada'::character varying, 'aprobado'::character varying])::text[])))`

### `usuarios`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `usuario_id` | uuid | no |  |
| `nombre_usuario` | character varying(255) | no |  |
| `correo_electronico` | character varying(255) | no |  |
| `correo_verificado_en` | timestamp(0) with time zone | sí |  |
| `contrasena` | character varying(255) | no |  |
| `usuario_activo` | boolean | no | `true` |
| `codigo_recordarme` | character varying(100) | sí |  |
| `secreto_dos_factores` | text | sí |  |
| `codigos_recuperacion_dos_factores` | text | sí |  |
| `dos_factores_confirmado_en` | timestamp(0) with time zone | sí |  |
| `formacion_experiencia_usuario` | text | sí |  |
| `preferencias_accesibilidad_usuario` | jsonb | sí |  |
| `preferencias_correo_usuario` | jsonb | sí |  |

- PK: `PRIMARY KEY (usuario_id)`
- Únicos:
  - `usuarios_correo_electronico_unique`: `(correo_electronico)`

### `valores_campo`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `valor_campo_id` | uuid | no |  |
| `silabo_id` | uuid | sí |  |
| `definicion_campo_id` | uuid | no |  |
| `valor_campo` | jsonb | sí |  |
| `asignatura_id` | uuid | sí |  |

- PK: `PRIMARY KEY (valor_campo_id)`
- FK:
  - `valores_campo_asignatura_id_foreign`: `FOREIGN KEY (asignatura_id) REFERENCES asignaturas(asignatura_id) ON DELETE CASCADE`
  - `valores_campo_definicion_campo_id_foreign`: `FOREIGN KEY (definicion_campo_id) REFERENCES definiciones_campo(definicion_campo_id) ON DELETE RESTRICT`
  - `valores_campo_silabo_id_foreign`: `FOREIGN KEY (silabo_id) REFERENCES silabos(silabo_id) ON DELETE CASCADE`
- Únicos:
  - `valores_campo_asignatura_definicion_unica`: `(asignatura_id, definicion_campo_id) WHERE (asignatura_id IS NOT NULL)`
  - `valores_campo_silabo_definicion_unica`: `(silabo_id, definicion_campo_id) WHERE (silabo_id IS NOT NULL)`
- CHECK:
  - `valores_campo_un_solo_dueno_check`: `CHECK (((silabo_id IS NULL) <> (asignatura_id IS NULL)))`

## Tablas del framework

Laravel las usa con nombres en español por configuración. Sus columnas conservan los nombres que exige cada driver.

### `bloqueos_temporales`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `key` | character varying(255) | no |  |
| `owner` | character varying(255) | no |  |
| `expiration` | bigint | no |  |

- PK: `PRIMARY KEY (key)`

### `cola_trabajos`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `id` | bigint | no | `nextval('cola_trabajos_id_seq'::regclass)` |
| `queue` | character varying(255) | no |  |
| `payload` | text | no |  |
| `attempts` | smallint | no |  |
| `reserved_at` | integer | sí |  |
| `available_at` | integer | no |  |
| `created_at` | integer | no |  |

- PK: `PRIMARY KEY (id)`

### `datos_temporales`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `key` | character varying(255) | no |  |
| `value` | text | no |  |
| `expiration` | bigint | no |  |

- PK: `PRIMARY KEY (key)`

### `migraciones`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `id` | integer | no | `nextval('migraciones_id_seq'::regclass)` |
| `migration` | character varying(255) | no |  |
| `batch` | integer | no |  |

- PK: `PRIMARY KEY (id)`

### `restablecimientos_contrasena`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `email` | character varying(255) | no |  |
| `token` | character varying(255) | no |  |
| `created_at` | timestamp(0) with time zone | sí |  |

- PK: `PRIMARY KEY (email)`

### `sesiones`

| Columna | Tipo | Nulo | Por defecto |
|---|---|---|---|
| `id` | character varying(255) | no |  |
| `user_id` | uuid | sí |  |
| `ip_address` | character varying(45) | sí |  |
| `user_agent` | text | sí |  |
| `payload` | text | no |  |
| `last_activity` | integer | no |  |

- PK: `PRIMARY KEY (id)`
- FK:
  - `sesiones_user_id_foreign`: `FOREIGN KEY (user_id) REFERENCES usuarios(usuario_id) ON DELETE SET NULL`

## Anexo: consultas

Tablas (`gen.sql`):

````sql
-- Genera Markdown del esquema public. Solo lectura sobre pg_catalog.
with tabla as (
  select c.oid, c.relname,
         c.relname in ('migraciones','sesiones','restablecimientos_contrasena','cola_trabajos','datos_temporales','bloqueos_temporales') as framework
  from pg_class c join pg_namespace n on n.oid = c.relnamespace
  where n.nspname = 'public' and c.relkind in ('r','p')
),
seccion as (
  select t.framework, t.relname,
    '### `' || t.relname || '`' || E'\n\n' ||
    '| Columna | Tipo | Nulo | Por defecto |' || E'\n' || '|---|---|---|---|' || E'\n' ||
    (select string_agg(format('| `%s` | %s | %s | %s |',
              a.attname,
              replace(format_type(a.atttypid, a.atttypmod), '|', '\|'),
              case when a.attnotnull then 'no' else 'sí' end,
              coalesce(case when a.attgenerated = 's' then 'generada: `' || replace(pg_get_expr(d.adbin, d.adrelid), '|', '\|') || '`'
                            else '`' || replace(pg_get_expr(d.adbin, d.adrelid), '|', '\|') || '`' end, '')),
            E'\n' order by a.attnum)
       from pg_attribute a
       left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
      where a.attrelid = t.oid and a.attnum > 0 and not a.attisdropped) || E'\n\n' ||
    coalesce((select '- PK: `' || pg_get_constraintdef(k.oid) || '`' || E'\n'
                from pg_constraint k where k.conrelid = t.oid and k.contype = 'p'), '- PK: ninguna' || E'\n') ||
    coalesce((select '- FK:' || E'\n' || string_agg(format('  - `%s`: `%s`', k.conname, pg_get_constraintdef(k.oid)), E'\n' order by k.conname) || E'\n'
                from pg_constraint k where k.conrelid = t.oid and k.contype = 'f'), '') ||
    coalesce((select '- Únicos:' || E'\n' || string_agg(format('  - `%s`: `%s`', ic.relname,
                       replace(regexp_replace(pg_get_indexdef(i.indexrelid), '^CREATE UNIQUE INDEX \S+ ON \S+ USING btree ', ''), '|', '\|')),
                       E'\n' order by ic.relname) || E'\n'
                from pg_index i join pg_class ic on ic.oid = i.indexrelid
               where i.indrelid = t.oid and i.indisunique and not i.indisprimary), '') ||
    coalesce((select '- CHECK:' || E'\n' || string_agg(format('  - `%s`: `%s`', k.conname, replace(pg_get_constraintdef(k.oid), '|', '\|')), E'\n' order by k.conname) || E'\n'
                from pg_constraint k where k.conrelid = t.oid and k.contype = 'c'), '') ||
    coalesce((select '- Triggers:' || E'\n' || string_agg(format('  - `%s`: %s', g.tgname,
                       regexp_replace(regexp_replace(pg_get_triggerdef(g.oid), '^CREATE (CONSTRAINT )?TRIGGER \S+ ', ''), ' ON public\.\S+', '')),
                       E'\n' order by g.tgname) || E'\n'
                from pg_trigger g where g.tgrelid = t.oid and not g.tgisinternal), '') as md
  from tabla t
)
select string_agg(md, E'\n' order by framework, relname) filter (where not framework) ||
       E'\n## Tablas del framework\n\nLaravel las usa con nombres en español por configuración. Sus columnas conservan los nombres que exige cada driver.\n\n' ||
       string_agg(md, E'\n' order by relname) filter (where framework)
from seccion;
````

Vistas (`views.sql`):

````sql
select string_agg('### `' || c.relname || '`' || E'\n\n```sql\n' || trim(pg_get_viewdef(c.oid, true)) || E'\n```\n', E'\n' order by c.relname)
from pg_class c join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and c.relkind = 'v';
````

Funciones (`functions.sql`):

````sql
select string_agg(format('- `%s()`: %s', p.proname,
         coalesce('triggers en ' || (select string_agg(distinct '`' || g.tgrelid::regclass::text || '`', ', ') from pg_trigger g where g.tgfoid = p.oid and not g.tgisinternal),
                  'ningún trigger la llama directamente')), E'\n' order by p.proname)
from pg_proc p join pg_namespace n on n.oid = p.pronamespace
where n.nspname = 'public'
  and not exists (select 1 from pg_depend d where d.classid = 'pg_proc'::regclass and d.objid = p.oid and d.deptype = 'e');
````

Tipos enumerados (`enums.sql`):

````sql
select string_agg(format('- `%s`: %s', t.typname, (select string_agg('`' || e.enumlabel || '`', ', ' order by e.enumsortorder) from pg_enum e where e.enumtypid = t.oid)), E'\n' order by t.typname)
from pg_type t join pg_namespace n on n.oid = t.typnamespace
where n.nspname = 'public' and t.typtype = 'e';
````

Extensiones y última migración:

````sql
select extname, extversion from pg_extension where extname <> 'plpgsql';
select migration from migraciones order by id desc limit 1;
````
