-- I-168: limpieza de la base (migraciones 000166 a 000170) para la base remota.
-- Antes de ejecutar: haga un respaldo. Ejecute todo de una vez; si algo falla, nada se aplica.
BEGIN;

-- ===== 000166: retirar restos sin uso =====
DROP TRIGGER IF EXISTS eliminar_documentos_ia_de_fuente ON fuentes_academicas;
DROP FUNCTION IF EXISTS eliminar_documentos_ia_de_fuente();
DROP TABLE IF EXISTS documentos_ia;
DO $$ BEGIN DROP EXTENSION IF EXISTS vector; EXCEPTION WHEN insufficient_privilege OR dependent_objects_still_exist THEN NULL; END $$;
DROP TABLE IF EXISTS resultados_aprendizaje_asignatura;
DROP TABLE IF EXISTS bases_resultados_asignatura;

SET LOCAL app.reinicio_institucional = 'on';
DELETE FROM historial_ia WHERE mensaje_ia ->> 'tipo' NOT IN ('mensaje', 'ocultamiento') OR sesion_id !~ '^silabo:[0-9a-f-]{36}:usuario:[0-9a-f-]{36}$';
RESET app.reinicio_institucional;
DROP INDEX IF EXISTS historial_ia_analisis_idempotencia_unica;
DROP INDEX IF EXISTS historial_ia_aplicada_unica;
DROP INDEX IF EXISTS historial_ia_decision_unica;
DROP INDEX IF EXISTS historial_ia_revision_version_unica;
ALTER TABLE historial_ia DROP CONSTRAINT historial_ia_mensaje_check;
ALTER TABLE historial_ia ADD CONSTRAINT historial_ia_mensaje_check CHECK (
    jsonb_typeof(mensaje_ia) = 'object'
    AND jsonb_typeof(mensaje_ia -> 'entrada') = 'object'
    AND mensaje_ia ->> 'tipo' IN ('mensaje', 'ocultamiento')
    AND mensaje_ia ->> 'estado' IN ('pendiente', 'en_ejecucion', 'completado', 'fallido', 'registrada'));
ALTER TABLE historial_ia DROP CONSTRAINT historial_ia_sesion_check;
ALTER TABLE historial_ia ADD CONSTRAINT historial_ia_sesion_check CHECK (sesion_id ~ '^silabo:[0-9a-f-]{36}:usuario:[0-9a-f-]{36}$');

DROP TRIGGER IF EXISTS validar_solicitud_correccion ON solicitudes_correccion;

CREATE OR REPLACE FUNCTION validar_artefacto_exportacion() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF reinicio_institucional_habilitado() THEN
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Un artefacto de exportación no puede eliminarse' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.estado_artefacto_exportacion = 'completado' AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'Un artefacto completado es inmutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS tocar_revision_plantilla_directa ON plantilla_silabo;
DROP TRIGGER IF EXISTS tocar_revision_por_campo ON campos_plantilla;
DROP TRIGGER IF EXISTS tocar_revision_por_campo ON definiciones_campo;
DROP TRIGGER IF EXISTS tocar_revision_por_seccion ON secciones_plantilla;
DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla();
DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla_directa();
ALTER TABLE plantilla_silabo DROP COLUMN IF EXISTS revision_configuracion;

ALTER TABLE definiciones_campo DROP COLUMN IF EXISTS ia_habilitada;
ALTER TABLE definiciones_campo DROP COLUMN IF EXISTS marcador_documento;
ALTER TABLE valores_campo DROP COLUMN IF EXISTS heredado;
ALTER TABLE valores_campo DROP COLUMN IF EXISTS origen_valor_campo;
ALTER TABLE fuentes_academicas DROP COLUMN IF EXISTS contenido_fuente_academica;
ALTER TABLE fuentes_academicas DROP COLUMN IF EXISTS version_documento_fuente;
ALTER TABLE fuentes_academicas DROP COLUMN IF EXISTS descripcion_fuente_academica;
ALTER TABLE artefactos_exportacion DROP COLUMN IF EXISTS asignacion_rol_id;

DELETE FROM migraciones WHERE migration = '2026_08_14_000009_create_institutional_import_tables';

-- ===== 000167: reglas de integridad =====
CREATE TRIGGER proteger_firma_documento BEFORE DELETE OR UPDATE ON firmas_documento FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only();
ALTER TABLE carreras ADD CONSTRAINT carreras_modalidad_check CHECK (modalidad_carrera IN ('presencial', 'semipresencial', 'en_linea', 'a_distancia', 'hibrida'));
ALTER TABLE asignaturas ADD CONSTRAINT asignaturas_modalidad_check CHECK (modalidad_asignatura IS NULL OR modalidad_asignatura IN ('presencial', 'semipresencial', 'en_linea', 'a_distancia', 'hibrida'));
ALTER TABLE requisitos_asignatura ADD CONSTRAINT requisitos_asignatura_tipo_check CHECK (tipo_requisito_asignatura IN ('prerrequisito', 'correquisito'));
ALTER TABLE requisitos_asignatura DROP CONSTRAINT requisitos_asignatura_asignatura_id_requisito_id_tipo_requisito;
ALTER TABLE requisitos_asignatura ADD CONSTRAINT requisitos_asignatura_par_unico UNIQUE (asignatura_id, requisito_id);
ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_heredado_check CHECK (NOT heredado OR (origen_maestro IS NOT NULL AND NOT editable_docente));
ALTER TABLE asignaciones_rol ALTER COLUMN asignado_en SET DEFAULT CURRENT_TIMESTAMP;
UPDATE asignaciones_rol SET asignado_en = CURRENT_TIMESTAMP WHERE asignado_en IS NULL;
ALTER TABLE asignaciones_rol ALTER COLUMN asignado_en SET NOT NULL;
ALTER TABLE asignaciones_paralelo ALTER COLUMN asignado_en SET DEFAULT CURRENT_TIMESTAMP;
UPDATE asignaciones_paralelo SET asignado_en = CURRENT_TIMESTAMP WHERE asignado_en IS NULL;
ALTER TABLE asignaciones_paralelo ALTER COLUMN asignado_en SET NOT NULL;
ALTER TABLE objetos_almacenados DROP CONSTRAINT objetos_almacenados_estado_check;
ALTER TABLE objetos_almacenados ADD CONSTRAINT objetos_almacenados_estado_check CHECK (estado_objeto_almacenado = 'activo');
ALTER TABLE eventos_auditoria DROP CONSTRAINT eventos_auditoria_resultado_check;
ALTER TABLE eventos_auditoria ADD CONSTRAINT eventos_auditoria_resultado_check CHECK (resultado_evento_auditoria IN ('exito', 'fallido'));

-- ===== 000168: índices y nombres =====
DROP INDEX IF EXISTS importaciones_curriculares_carrera_id_index;
DROP INDEX IF EXISTS asignaturas_asignatura_activa_index;
DROP INDEX IF EXISTS carreras_carrera_activa_index;
DROP INDEX IF EXISTS facultades_facultad_activa_index;
DROP INDEX IF EXISTS programaciones_asignatura_activa_index;
DROP INDEX IF EXISTS fuentes_academicas_activa_index;
DROP INDEX IF EXISTS procesos_silabos_estado_idx;
DROP INDEX IF EXISTS valores_curriculares_carrera_id_index;
DROP INDEX IF EXISTS valores_curriculares_concepto_id_index;
CREATE INDEX valores_curriculares_concepto_id_carrera_id_index ON valores_curriculares (concepto_id, carrera_id);
ALTER TABLE valores_curriculares DROP CONSTRAINT IF EXISTS valores_curriculares_carrera_id_foreign;

CREATE OR REPLACE VIEW docentes_vigentes_silabo AS
SELECT silabos.id AS silabo_id,
    asignaciones_rol.usuario_id,
    asignaciones_rol.id AS asignacion_rol_id,
    asignaciones_paralelo.id AS asignacion_paralelo_id,
    silabos.paralelo_id
FROM silabos
JOIN asignaciones_paralelo ON asignaciones_paralelo.paralelo_id = silabos.paralelo_id
    AND asignaciones_paralelo.docente_paralelo_activo
JOIN asignaciones_rol ON asignaciones_rol.id = asignaciones_paralelo.asignacion_rol_id
    AND asignaciones_rol.asignacion_rol_activa
    AND asignaciones_rol.rol = 'docente';

ALTER TABLE artefactos_exportacion RENAME CONSTRAINT exportacion_revision_idempotencia_unica TO artefactos_exportacion_revision_idempotencia_unica;
ALTER TABLE convocatorias_carreras RENAME CONSTRAINT convocatorias_estado_check TO convocatorias_carreras_estado_check;
ALTER TABLE convocatorias_universidad RENAME CONSTRAINT procesos_silabos_fechas_check TO convocatorias_universidad_fechas_check;
ALTER TABLE periodos_academicos RENAME CONSTRAINT periodos_fechas_check TO periodos_academicos_fechas_check;
ALTER TABLE periodos_academicos RENAME CONSTRAINT periodos_semanas_lectivas_rango TO periodos_academicos_semanas_lectivas_rango;
ALTER TABLE periodos_academicos RENAME CONSTRAINT periodos_codigo_periodo_academico_unico TO periodos_academicos_codigo_unico;
ALTER TABLE programaciones_asignatura RENAME CONSTRAINT programacion_asignatura_periodo_materia_unica TO programaciones_asignatura_periodo_materia_unica;
ALTER INDEX IF EXISTS asignacion_rol_activa_unica RENAME TO asignaciones_rol_activa_unica;
ALTER INDEX IF EXISTS campanias_carrera_id_estado_index RENAME TO convocatorias_carreras_carrera_id_estado_index;
ALTER INDEX IF EXISTS convocatorias_carrera_proceso_unico RENAME TO convocatorias_carreras_carrera_proceso_unico;
ALTER INDEX IF EXISTS procesos_silabos_en_curso_unico RENAME TO convocatorias_universidad_en_curso_unico;
ALTER INDEX IF EXISTS procesos_silabos_periodo_unico RENAME TO convocatorias_universidad_periodo_unico;
ALTER INDEX IF EXISTS auditoria_actor_fecha_idx RENAME TO eventos_auditoria_actor_fecha_idx;
ALTER INDEX IF EXISTS auditoria_recurso_fecha_idx RENAME TO eventos_auditoria_recurso_fecha_idx;

-- ===== 000169: columnas con nombre completo =====
ALTER TABLE convocatorias_carreras RENAME COLUMN proceso_id TO convocatoria_universidad_id;
ALTER TABLE silabos RENAME COLUMN convocatoria_id TO convocatoria_carrera_id;
ALTER TABLE asignaciones_paralelo RENAME COLUMN docente_paralelo_activo TO asignacion_paralelo_activa;
ALTER TABLE ejecuciones_validacion RENAME COLUMN errores_bloqueantes TO cantidad_errores_validacion;
ALTER TABLE definiciones_campo RENAME COLUMN heredado TO heredado_definicion_campo;
ALTER TABLE definiciones_campo RENAME COLUMN origen_maestro TO origen_maestro_definicion_campo;
ALTER TABLE definiciones_campo RENAME COLUMN editable_docente TO editable_docente_definicion_campo;
ALTER TABLE definiciones_campo RENAME COLUMN mostrar_si TO mostrar_si_definicion_campo;

CREATE OR REPLACE FUNCTION impedir_cambio_periodo_proceso_con_convocatorias() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.periodo_academico_id IS DISTINCT FROM OLD.periodo_academico_id
       AND EXISTS (SELECT 1 FROM convocatorias_carreras WHERE convocatoria_universidad_id = OLD.id) THEN
        RAISE EXCEPTION 'No se puede cambiar el período de una convocatoria universitaria con convocatorias de carrera.';
    END IF;
    RETURN NEW;
END;
$$;

-- Nombres de restricciones según la convención {tabla}_{columna}_not_null / _pkey / _foreign
ALTER TABLE migraciones RENAME CONSTRAINT "migrations_id_not_null" TO "migraciones_id_not_null";
ALTER TABLE migraciones RENAME CONSTRAINT "migrations_migration_not_null" TO "migraciones_migration_not_null";
ALTER TABLE migraciones RENAME CONSTRAINT "migrations_batch_not_null" TO "migraciones_batch_not_null";
ALTER TABLE usuarios RENAME CONSTRAINT "usuarios_name_not_null" TO "usuarios_nombre_usuario_not_null";
ALTER TABLE usuarios RENAME CONSTRAINT "usuarios_email_not_null" TO "usuarios_correo_electronico_not_null";
ALTER TABLE usuarios RENAME CONSTRAINT "usuarios_password_not_null" TO "usuarios_contrasena_not_null";
ALTER TABLE usuarios RENAME CONSTRAINT "usuarios_active_not_null" TO "usuarios_usuario_activo_not_null";
ALTER TABLE restablecimientos_contrasena RENAME CONSTRAINT "password_reset_tokens_email_not_null" TO "restablecimientos_contrasena_email_not_null";
ALTER TABLE restablecimientos_contrasena RENAME CONSTRAINT "password_reset_tokens_token_not_null" TO "restablecimientos_contrasena_token_not_null";
ALTER TABLE sesiones RENAME CONSTRAINT "sessions_id_not_null" TO "sesiones_id_not_null";
ALTER TABLE sesiones RENAME CONSTRAINT "sessions_payload_not_null" TO "sesiones_payload_not_null";
ALTER TABLE sesiones RENAME CONSTRAINT "sessions_last_activity_not_null" TO "sesiones_last_activity_not_null";
ALTER TABLE trabajos_fallidos RENAME CONSTRAINT "failed_jobs_id_not_null" TO "trabajos_fallidos_id_not_null";
ALTER TABLE trabajos_fallidos RENAME CONSTRAINT "failed_jobs_uuid_not_null" TO "trabajos_fallidos_uuid_not_null";
ALTER TABLE trabajos_fallidos RENAME CONSTRAINT "failed_jobs_connection_not_null" TO "trabajos_fallidos_connection_not_null";
ALTER TABLE trabajos_fallidos RENAME CONSTRAINT "failed_jobs_queue_not_null" TO "trabajos_fallidos_queue_not_null";
ALTER TABLE trabajos_fallidos RENAME CONSTRAINT "failed_jobs_payload_not_null" TO "trabajos_fallidos_payload_not_null";
ALTER TABLE trabajos_fallidos RENAME CONSTRAINT "failed_jobs_exception_not_null" TO "trabajos_fallidos_exception_not_null";
ALTER TABLE trabajos_fallidos RENAME CONSTRAINT "failed_jobs_failed_at_not_null" TO "trabajos_fallidos_failed_at_not_null";
ALTER TABLE ejecuciones_trabajo RENAME CONSTRAINT "ejecuciones_trabajo_type_not_null" TO "ejecuciones_trabajo_tipo_ejecucion_trabajo_not_null";
ALTER TABLE ejecuciones_trabajo RENAME CONSTRAINT "ejecuciones_trabajo_status_not_null" TO "ejecuciones_trabajo_estado_ejecucion_trabajo_not_null";
ALTER TABLE ejecuciones_trabajo RENAME CONSTRAINT "ejecuciones_trabajo_idempotency_key_not_null" TO "ejecuciones_trabajo_clave_idempotencia_not_null";
ALTER TABLE ejecuciones_trabajo RENAME CONSTRAINT "ejecuciones_trabajo_attempts_not_null" TO "ejecuciones_trabajo_intentos_not_null";
ALTER TABLE ejecuciones_trabajo RENAME CONSTRAINT "ejecuciones_trabajo_progress_not_null" TO "ejecuciones_trabajo_progreso_not_null";
ALTER TABLE asignaciones_rol RENAME CONSTRAINT "asignaciones_rol_activo_not_null" TO "asignaciones_rol_asignacion_rol_activa_not_null";
ALTER TABLE facultades RENAME CONSTRAINT "facultades_nombre_not_null" TO "facultades_nombre_facultad_not_null";
ALTER TABLE facultades RENAME CONSTRAINT "facultades_activo_not_null" TO "facultades_facultad_activa_not_null";
ALTER TABLE periodos_academicos RENAME CONSTRAINT "periodos_academicos_codigo_not_null" TO "periodos_academicos_codigo_periodo_academico_not_null";
ALTER TABLE periodos_academicos RENAME CONSTRAINT "periodos_academicos_fecha_inicio_not_null" TO "periodos_academicos_fecha_inicio_periodo_not_null";
ALTER TABLE periodos_academicos RENAME CONSTRAINT "periodos_academicos_fecha_fin_not_null" TO "periodos_academicos_fecha_fin_periodo_not_null";
ALTER TABLE carreras RENAME CONSTRAINT "carreras_nombre_not_null" TO "carreras_nombre_carrera_not_null";
ALTER TABLE carreras RENAME CONSTRAINT "carreras_activo_not_null" TO "carreras_carrera_activa_not_null";
ALTER TABLE firmas_documento RENAME CONSTRAINT "firmas_documento_artefacto_exportacion_id_fkey" TO "firmas_documento_artefacto_exportacion_id_foreign";
ALTER TABLE asignaturas RENAME CONSTRAINT "asignaturas_codigo_institucional_not_null" TO "asignaturas_codigo_asignatura_not_null";
ALTER TABLE asignaturas RENAME CONSTRAINT "asignaturas_nombre_not_null" TO "asignaturas_nombre_asignatura_not_null";
ALTER TABLE asignaturas RENAME CONSTRAINT "asignaturas_activo_not_null" TO "asignaturas_asignatura_activa_not_null";
ALTER TABLE firmas_documento RENAME CONSTRAINT "firmas_documento_usuario_id_fkey" TO "firmas_documento_usuario_id_foreign";
ALTER TABLE requisitos_asignatura RENAME CONSTRAINT "requisitos_asignatura_tipo_not_null" TO "requisitos_asignatura_tipo_requisito_asignatura_not_null";
ALTER TABLE firmas_documento RENAME CONSTRAINT "firmas_documento_objeto_almacenado_id_fkey" TO "firmas_documento_objeto_almacenado_id_foreign";
ALTER TABLE paralelos RENAME CONSTRAINT "paralelos_oferta_academica_id_not_null" TO "paralelos_programacion_asignatura_id_not_null";
ALTER TABLE paralelos RENAME CONSTRAINT "paralelos_codigo_not_null" TO "paralelos_codigo_paralelo_not_null";
ALTER TABLE asignaciones_paralelo RENAME CONSTRAINT "asignaciones_docente_id_not_null" TO "asignaciones_paralelo_id_not_null";
ALTER TABLE asignaciones_paralelo RENAME CONSTRAINT "asignaciones_docente_paralelo_id_not_null" TO "asignaciones_paralelo_paralelo_id_not_null";
ALTER TABLE asignaciones_paralelo RENAME CONSTRAINT "asignaciones_docente_activo_not_null" TO "asignaciones_paralelo_asignacion_paralelo_activa_not_null";
ALTER TABLE eventos_auditoria RENAME CONSTRAINT "eventos_auditoria_accion_not_null" TO "eventos_auditoria_accion_evento_auditoria_not_null";
ALTER TABLE eventos_auditoria RENAME CONSTRAINT "eventos_auditoria_resultado_not_null" TO "eventos_auditoria_resultado_evento_auditoria_not_null";
ALTER TABLE convocatorias_universidad RENAME CONSTRAINT "procesos_silabos_periodo_academico_id_foreign" TO "convocatorias_universidad_periodo_academico_id_foreign";
ALTER TABLE convocatorias_universidad RENAME CONSTRAINT "procesos_silabos_periodo_academico_id_not_null" TO "convocatorias_universidad_periodo_academico_id_not_null";
ALTER TABLE programaciones_asignatura RENAME CONSTRAINT "programaciones_asignatura_activo_not_null" TO "programaciones_asignatura_programacion_asignatura_activa_not_nu";
ALTER TABLE plantilla_silabo RENAME CONSTRAINT "plantillas_silabo_id_not_null" TO "plantilla_silabo_id_not_null";
ALTER TABLE plantilla_silabo RENAME CONSTRAINT "plantillas_silabo_pkey" TO "plantilla_silabo_pkey";
ALTER TABLE periodos_academicos RENAME CONSTRAINT "periodos_academicos_semanas_lectivas_not_null" TO "periodos_academicos_cantidad_semanas_lectivas_not_null";
ALTER TABLE definiciones_campo RENAME CONSTRAINT "definiciones_campo_clave_not_null" TO "definiciones_campo_clave_definicion_campo_not_null";
ALTER TABLE definiciones_campo RENAME CONSTRAINT "definiciones_campo_etiqueta_not_null" TO "definiciones_campo_etiqueta_definicion_campo_not_null";
ALTER TABLE definiciones_campo RENAME CONSTRAINT "definiciones_campo_tipo_not_null" TO "definiciones_campo_tipo_definicion_campo_not_null";
ALTER TABLE definiciones_campo RENAME CONSTRAINT "definiciones_campo_heredado_not_null" TO "definiciones_campo_heredado_definicion_campo_not_null";
ALTER TABLE definiciones_campo RENAME CONSTRAINT "definiciones_campo_editable_docente_not_null" TO "definiciones_campo_editable_docente_definicion_campo_not_null";
ALTER TABLE definiciones_campo RENAME CONSTRAINT "definiciones_campo_posicion_not_null" TO "definiciones_campo_posicion_definicion_campo_not_null";
ALTER TABLE fuentes_academicas RENAME CONSTRAINT "fuentes_academicas_nombre_not_null" TO "fuentes_academicas_nombre_fuente_academica_not_null";
ALTER TABLE fuentes_academicas RENAME CONSTRAINT "fuentes_academicas_activo_not_null" TO "fuentes_academicas_fuente_academica_activa_not_null";
ALTER TABLE asignaciones_paralelo RENAME CONSTRAINT "docentes_paralelo_asignacion_rol_id_not_null" TO "asignaciones_paralelo_asignacion_rol_id_not_null";
ALTER TABLE silabos RENAME CONSTRAINT "silabos_estado_not_null" TO "silabos_estado_silabo_not_null";
ALTER TABLE silabos RENAME CONSTRAINT "silabos_lock_version_not_null" TO "silabos_version_bloqueo_not_null";
ALTER TABLE silabos RENAME CONSTRAINT "silabos_campania_id_foreign" TO "silabos_convocatoria_carrera_id_foreign";
ALTER TABLE convocatorias_carreras RENAME CONSTRAINT "campanias_id_not_null" TO "convocatorias_carreras_id_not_null";
ALTER TABLE convocatorias_carreras RENAME CONSTRAINT "campanias_carrera_id_not_null" TO "convocatorias_carreras_carrera_id_not_null";
ALTER TABLE convocatorias_carreras RENAME CONSTRAINT "campanias_estado_not_null" TO "convocatorias_carreras_estado_convocatoria_carrera_not_null";
ALTER TABLE convocatorias_carreras RENAME CONSTRAINT "campanias_carrera_id_foreign" TO "convocatorias_carreras_carrera_id_foreign";
ALTER TABLE convocatorias_carreras RENAME CONSTRAINT "campanias_pkey" TO "convocatorias_carreras_pkey";
ALTER TABLE silabos RENAME CONSTRAINT "silabos_campania_id_not_null" TO "silabos_convocatoria_carrera_id_not_null";
ALTER TABLE revisiones_silabo RENAME CONSTRAINT "revisiones_silabo_lock_version_origen_not_null" TO "revisiones_silabo_version_bloqueo_origen_not_null";
ALTER TABLE ejecuciones_validacion RENAME CONSTRAINT "ejecuciones_validacion_estado_not_null" TO "ejecuciones_validacion_estado_ejecucion_validacion_not_null";
ALTER TABLE ejecuciones_validacion RENAME CONSTRAINT "ejecuciones_validacion_lock_version_not_null" TO "ejecuciones_validacion_version_bloqueo_not_null";
ALTER TABLE ejecuciones_validacion RENAME CONSTRAINT "ejecuciones_validacion_errores_bloqueantes_not_null" TO "ejecuciones_validacion_cantidad_errores_validacion_not_null";
ALTER TABLE ejecuciones_validacion RENAME CONSTRAINT "ejecuciones_validacion_advertencias_not_null" TO "ejecuciones_validacion_cantidad_advertencias_validacion_not_nul";
ALTER TABLE resultados_validacion RENAME CONSTRAINT "resultados_validacion_codigo_not_null" TO "resultados_validacion_codigo_resultado_validacion_not_null";
ALTER TABLE resultados_validacion RENAME CONSTRAINT "resultados_validacion_severidad_not_null" TO "resultados_validacion_severidad_resultado_validacion_not_null";
ALTER TABLE resultados_validacion RENAME CONSTRAINT "resultados_validacion_mensaje_not_null" TO "resultados_validacion_mensaje_resultado_validacion_not_null";
ALTER TABLE revisiones_silabo RENAME CONSTRAINT "revisiones_silabo_snapshot_not_null" TO "revisiones_silabo_fotografia_revision_silabo_not_null";
ALTER TABLE secciones_plantilla RENAME CONSTRAINT "secciones_plantilla_clave_not_null" TO "secciones_plantilla_clave_seccion_plantilla_not_null";
ALTER TABLE secciones_plantilla RENAME CONSTRAINT "secciones_plantilla_titulo_not_null" TO "secciones_plantilla_titulo_seccion_plantilla_not_null";
ALTER TABLE secciones_plantilla RENAME CONSTRAINT "secciones_plantilla_posicion_not_null" TO "secciones_plantilla_posicion_seccion_plantilla_not_null";
ALTER TABLE campos_plantilla RENAME CONSTRAINT "campos_plantilla_clave_not_null" TO "campos_plantilla_clave_campo_plantilla_not_null";
ALTER TABLE campos_plantilla RENAME CONSTRAINT "campos_plantilla_configuracion_not_null" TO "campos_plantilla_configuracion_campo_plantilla_not_null";
ALTER TABLE observaciones_revision RENAME CONSTRAINT "observaciones_revision_contenido_not_null" TO "observaciones_revision_contenido_observacion_revision_not_null";
ALTER TABLE observaciones_revision RENAME CONSTRAINT "observaciones_revision_estado_not_null" TO "observaciones_revision_estado_observacion_revision_not_null";
ALTER TABLE observaciones_revision RENAME CONSTRAINT "observaciones_revision_creado_en_not_null" TO "observaciones_revision_observado_en_not_null";
ALTER TABLE solicitudes_correccion RENAME CONSTRAINT "solicitudes_correccion_justificacion_not_null" TO "solicitudes_correccion_justificacion_solicitud_correccion_not_n";
ALTER TABLE aprobaciones RENAME CONSTRAINT "aprobaciones_aprobacion_base_id_fkey" TO "aprobaciones_aprobacion_base_id_foreign";
ALTER TABLE envios_biblioteca RENAME CONSTRAINT "envios_biblioteca_revision_silabo_id_fkey" TO "envios_biblioteca_revision_silabo_id_foreign";
ALTER TABLE envios_biblioteca RENAME CONSTRAINT "envios_biblioteca_enviado_por_fkey" TO "envios_biblioteca_enviado_por_foreign";
ALTER TABLE envios_biblioteca RENAME CONSTRAINT "envios_biblioteca_verificado_por_fkey" TO "envios_biblioteca_verificado_por_foreign";
ALTER TABLE campos_plantilla RENAME CONSTRAINT "campos_plantilla_posicion_not_null" TO "campos_plantilla_posicion_campo_plantilla_not_null";
ALTER TABLE campos_plantilla RENAME CONSTRAINT "campos_plantilla_titulo_not_null" TO "campos_plantilla_titulo_campo_plantilla_not_null";
ALTER TABLE ejecuciones_trabajo RENAME CONSTRAINT "ejecuciones_trabajo_queue_name_not_null" TO "ejecuciones_trabajo_cola_not_null";
ALTER TABLE ejecuciones_trabajo RENAME CONSTRAINT "ejecuciones_trabajo_max_attempts_not_null" TO "ejecuciones_trabajo_intentos_maximos_not_null";
ALTER TABLE objetos_almacenados RENAME CONSTRAINT "objetos_almacenados_estado_not_null" TO "objetos_almacenados_estado_objeto_almacenado_not_null";
ALTER TABLE objetos_almacenados RENAME CONSTRAINT "objetos_almacenados_creado_en_not_null" TO "objetos_almacenados_almacenado_en_not_null";
ALTER TABLE artefactos_exportacion RENAME CONSTRAINT "artefactos_exportacion_version_renderer_not_null" TO "artefactos_exportacion_version_renderizador_not_null";
ALTER TABLE artefactos_exportacion RENAME CONSTRAINT "artefactos_exportacion_locale_not_null" TO "artefactos_exportacion_idioma_not_null";
ALTER TABLE artefactos_exportacion RENAME CONSTRAINT "artefactos_exportacion_estado_not_null" TO "artefactos_exportacion_estado_artefacto_exportacion_not_null";
ALTER TABLE notificaciones_internas RENAME CONSTRAINT "notificaciones_internas_tipo_not_null" TO "notificaciones_internas_tipo_notificacion_interna_not_null";
ALTER TABLE notificaciones_internas RENAME CONSTRAINT "notificaciones_internas_titulo_not_null" TO "notificaciones_internas_titulo_notificacion_interna_not_null";
ALTER TABLE notificaciones_internas RENAME CONSTRAINT "notificaciones_internas_mensaje_not_null" TO "notificaciones_internas_mensaje_notificacion_interna_not_null";
ALTER TABLE notificaciones_internas RENAME CONSTRAINT "notificaciones_internas_creado_en_not_null" TO "notificaciones_internas_notificado_en_not_null";
ALTER TABLE asignaturas RENAME CONSTRAINT "asignaturas_orden_en_ciclo_not_null" TO "asignaturas_orden_asignatura_en_ciclo_not_null";
ALTER TABLE silabos RENAME CONSTRAINT "silabos_contexto_academico_not_null" TO "silabos_contexto_academico_silabo_not_null";
ALTER TABLE convocatorias_universidad RENAME CONSTRAINT "procesos_silabos_id_not_null" TO "convocatorias_universidad_id_not_null";
ALTER TABLE convocatorias_universidad RENAME CONSTRAINT "procesos_silabos_inicia_en_not_null" TO "convocatorias_universidad_inicia_en_not_null";
ALTER TABLE convocatorias_universidad RENAME CONSTRAINT "procesos_silabos_entrega_en_not_null" TO "convocatorias_universidad_entrega_en_not_null";
ALTER TABLE convocatorias_universidad RENAME CONSTRAINT "procesos_silabos_estado_not_null" TO "convocatorias_universidad_estado_convocatoria_universidad_not_n";
ALTER TABLE convocatorias_universidad RENAME CONSTRAINT "procesos_silabos_pkey" TO "convocatorias_universidad_pkey";
ALTER TABLE convocatorias_carreras RENAME CONSTRAINT "convocatorias_proceso_id_foreign" TO "convocatorias_carreras_convocatoria_universidad_id_foreign";
ALTER TABLE convocatorias_carreras RENAME CONSTRAINT "convocatorias_proceso_id_not_null" TO "convocatorias_carreras_convocatoria_universidad_id_not_null";

-- ===== 000170: cada campo se guarda una sola vez =====
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"table","content":[{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":13,"rowspan":1,"colwidth":[99,34,26,11,41,53,18,9,86,39,65,4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#4F81BD"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"FACULTAD:","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#FFFFFF"}},{"type":"bold"}]},{"type":"variable","attrs":{"id":"nombre_facultad","label":"nombre_facultad"},"marks":[{"type":"textStyle","attrs":{"color":"#FFFFFF"}},{"type":"bold"}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":1,"rowspan":1,"colwidth":[99],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"CARRERA:","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":6,"rowspan":1,"colwidth":[34,26,11,41,53,18],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"nombre_carrera","label":"nombre_carrera"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[9,86,39],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"MODALIDAD DE ESTUDIO O APRENDIZAJE","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[65,4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"modalidad_estudio","label":"modalidad_estudio"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":5,"rowspan":1,"colwidth":[99,34,26,11,41],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"CAMPUS UNIVERSITARIO:","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":8,"rowspan":1,"colwidth":[53,18,9,86,39,65,4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"nombre_campus","label":"nombre_campus"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[99,34,26],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"ASIGNATURA","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":5,"rowspan":1,"colwidth":[11,41,53,18,9],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"PERIODO ACADÉMICO","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":1,"rowspan":1,"colwidth":[86],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"CICLO","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[39,65],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"PARALELO","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"JORNADA","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[99,34,26],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"nombre_asignatura","label":"nombre_asignatura"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":5,"rowspan":1,"colwidth":[11,41,53,18,9],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"nombre_periodo_academico","label":"nombre_periodo_academico"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":1,"rowspan":1,"colwidth":[86],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"ciclo_asignatura","label":"ciclo_asignatura"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[39,65],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"codigo_paralelo","label":"codigo_paralelo"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"jornada_paralelo","label":"jornada_paralelo"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":1,"rowspan":1,"colwidth":[99],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"CÓDIGO:","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[34,26],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"codigo_asignatura","label":"codigo_asignatura"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[11,41,53],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"PRERREQUISITOS:","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[18,9,86],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"prerrequisitos_asignatura","label":"prerrequisitos_asignatura"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[39,65],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"CORREQUISITOS:","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"correquisitos_asignatura","label":"correquisitos_asignatura"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":3,"colwidth":[99,34,26],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"UNIDAD DE ORGANIZACIÓN CURRICULAR","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":8,"rowspan":1,"colwidth":[11,41,53,18,9,86,39,65],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"Unidad Básica","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"variable","attrs":{"id":"marca_unidad_basica","label":"marca_unidad_basica"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":8,"rowspan":1,"colwidth":[11,41,53,18,9,86,39,65],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"Unidad Profesional","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"variable","attrs":{"id":"marca_unidad_profesional","label":"marca_unidad_profesional"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":8,"rowspan":1,"colwidth":[11,41,53,18,9,86,39,65],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"Unidad de Titulación","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"variable","attrs":{"id":"marca_unidad_titulacion","label":"marca_unidad_titulacion"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":3,"colwidth":[99,34],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"ESTUDIANTES CON DISCAPACIDAD","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":2,"colwidth":[26,11],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"SÍ","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":1,"rowspan":2,"colwidth":[41],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"field","attrs":{"key":"discapacidad_tiene","choice":"Sí","listStyle":null}}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":8,"rowspan":1,"colwidth":[53,18,9,86,39,65,4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"Tipo de discapacidad:","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]},{"type":"field","attrs":{"key":"discapacidad_tipo","choice":null,"listStyle":null}}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":8,"rowspan":1,"colwidth":[53,18,9,86,39,65,4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"Descripción de la adaptación curricular:","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]},{"type":"field","attrs":{"key":"discapacidad_adaptacion","choice":null,"listStyle":null}}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[26,11],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"No","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":1,"rowspan":1,"colwidth":[41],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"field","attrs":{"key":"discapacidad_tiene","choice":"No","listStyle":null}}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":8,"rowspan":1,"colwidth":[53,18,9,86,39,65,4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"}}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":4,"colwidth":[99,34,26],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"COMPONENTES DE APRENDIZAJE EN EL PERÍODO ACADÉMICO","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[11,41,53],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"text":"HORAS DEL PROFESOR","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":6,"rowspan":1,"colwidth":[18,9,86,39,65,4],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"text":"HORAS DEL ESTUDIANTE","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":1,"rowspan":2,"colwidth":[141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"text":"TOTAL DE HORAS POR PERÍODO","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[11,41,53],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"text":"HORAS DE DOCENCIA (ACD)","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[18,9,86],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"text":"HORAS DE PRÁCTICAS DE APLICACIÓN O EXPERIMENTACIÓN (APE)","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[39,65,4],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"text":"HORAS DE APRENDIZAJE AUTÓNOMAS (AA)","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[11,41,53],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"variable","attrs":{"id":"horas_docencia","label":"horas_docencia"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[18,9,86],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"variable","attrs":{"id":"horas_practicas","label":"horas_practicas"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[39,65],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"variable","attrs":{"id":"horas_autonomas","label":"horas_autonomas"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"variable","attrs":{"id":"horas_totales","label":"horas_totales"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":8,"rowspan":1,"colwidth":[11,41,53,18,9,86,39,65],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"text":"TOTAL, CRÉDITOS","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":"#DBE5F1"},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"variable","attrs":{"id":"creditos_asignatura","label":"creditos_asignatura"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":3,"rowspan":1,"colwidth":[99,34,26],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"NOMBRE DEL DOCENTE","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":6,"rowspan":1,"colwidth":[11,41,53,18,9,86],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"nombre_docente","label":"nombre_docente"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[39,65],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"text":"CORREO INSTITUCIONAL","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}},{"type":"bold"}]}]}]},{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":2,"rowspan":1,"colwidth":[4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"correo_docente","label":"correo_docente"},"marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]}]}]},{"type":"tableRow","content":[{"type":"tableCell","attrs":{"bold":null,"italic":null,"colspan":13,"rowspan":1,"colwidth":[99,34,26,11,41,53,18,9,86,39,65,4,141],"textAlign":null,"textColor":null,"borderStyle":null,"backgroundColor":null},"content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"FORMACIÓN Y EXPERIENCIA ACADÉMICA – INVESTIGATIVA:","type":"text","marks":[{"type":"textStyle","attrs":{"color":"#000000"}}]}]},{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"field","attrs":{"key":"formacion_experiencia","choice":null,"listStyle":null}}]}]}]}]}]},"content_type":"institutional","detached_fields":{"campo_no_d80c6136":{"ia_habilitada":false,"editable_docente":true},"campo_tipo_de_discapacidad_7b329f84":{"ia_habilitada":false,"editable_docente":true},"campo_estudiantes_con_discapacidad_2e380c57":{"ia_habilitada":false,"editable_docente":true},"campo_identificacion_institucional_cda93816":{"ia_habilitada":false,"editable_docente":true},"campo_descripcion_de_la_adaptacion_curricular_fa27a8d4":{"ia_habilitada":false,"editable_docente":true}},"page_orientation":"portrait"}' WHERE id = '6c50e305-b7f6-4af4-a9bc-5f8d7628a39e';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Se aplicará el código de ética y políticas de acción afirmativa de la Universidad Estatal de Bolívar, además es importante compartir los principios de respeto mutuo dentro del ambiente virtual o salón de clase, entre los que se destacan los siguientes:","type":"text"}]},{"type":"bulletList","content":[{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Respeto:","type":"text","marks":[{"type":"bold"}]},{"text":"En las relaciones docente-estudiante y estudiante - estudiante será exigido en todo momento, garantizando un marco de respeto a la diversidad, de tal forma que se evidencie una convivencia armónica.","type":"text"}]}]},{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Trabajos de investigación:","type":"text","marks":[{"type":"bold"}]},{"text":"Se deberá incluir las citas y referencias de los autores consultados (de acuerdo a normativas aceptadas APA)","type":"text"}]}]},{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Entrega de Trabajos, Talleres y Pruebas:","type":"text","marks":[{"type":"bold"}]},{"text":"Deberán presentarse en las fechas y horas establecidas, caso contrario se calificará con una penalización.","type":"text"}]}]},{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Presentación de PAE:","type":"text","marks":[{"type":"bold"}]},{"text":"Las Prácticas de Aplicación y Experimentación se las desarrollará de acuerdo al formato suministrado por el profesor, y se lo entregará en las fechas establecidas.","type":"text"}]}]},{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Trabajo en Equipo:","type":"text","marks":[{"type":"bold"}]},{"text":"Reconocer y practicar el trabajo en equipo, la honestidad y la justicia en el tratamiento de la información, en el ámbito profesional y corporativo","type":"text"}]}]}]},{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Dentro de los estándares de comportamiento se considera lo siguiente:","type":"text"}]},{"type":"bulletList","content":[{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"La copia de tarea o trabajo ocasiona la pérdida total del porcentaje asignado a este rubro.","type":"text"}]}]},{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"La copia del examen de medio término se sancionará con UNO sobre diez de calificación en este rubro","type":"text"}]}]},{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Todas las ideas, comentarios, frases o trabajos de otros autores deberán llevar el crédito correspondiente","type":"text"}]}]},{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Se considera inadmisible lo siguiente: el plagio de frases u oraciones escritas, elementos gráficos, pruebas, ideas de trabajo de otros, publicadas o no publicadas; la entrega o envío de trabajos o exámenes que no son propiedad del estudiante; la falsificación de datos.","type":"text"}]}]},{"type":"listItem","content":[{"type":"paragraph","attrs":{"textAlign":"justify"},"content":[{"text":"Las violaciones serán analizadas por el profesor, el director de carrera y el estudiante","type":"text"}]}]}]}]},"content_type":"fixed","detached_fields":[]}' WHERE id = '14706393-f687-46d6-a353-c615c60a7352';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"descripcion_asignatura"}}]}]},"content_type":"text"}' WHERE id = '5c1a6ac6-48f6-4cc0-aa9d-f4704209ab0b';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"objetivo_general"}}]}]},"content_type":"text"}' WHERE id = '0c7b806a-a456-4f61-9258-06118b7cee66';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"objetivos_especificos"}}]}]},"content_type":"text"}' WHERE id = 'a8de13e9-acf7-479f-ada3-4f33640ab61e';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"Habilidades blandas de la asignatura:","type":"text","marks":[{"type":"bold"}]},{"type":"variable","attrs":{"id":"curricular_habilidades_blandas","label":"curricular_habilidades_blandas"}}]},{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"Habilidades blandas transversales:","type":"text","marks":[{"type":"bold"}]},{"type":"variable","attrs":{"id":"curricular_habilidades_transversales","label":"curricular_habilidades_transversales"}}]}]},"content_type":"text","detached_fields":[]}' WHERE id = '32daaf3b-3138-41e3-963c-f907e2b1d21d';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"unidades"}}]}]},"content_type":"text","page_orientation":"landscape"}' WHERE id = 'a63e77a6-c039-4262-b259-879d15dccf2f';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"metodologia"}}]}]},"content_type":"text"}' WHERE id = 'f4f31f9e-6404-4497-af46-6ae6188e027c';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"tecnicas_ensenanza"}}]}]},"content_type":"text"}' WHERE id = '6cd57f57-7b24-42bc-bab5-2dbc1354124d';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"recursos_medios"}}]}]},"content_type":"text"}' WHERE id = 'e06bac58-df97-4924-80f8-fb81d82a77b7';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"TIC","type":"text","marks":[{"type":"bold"}]}]},{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"curricular_herramientas_tic","label":"curricular_herramientas_tic"},"marks":[]}]},{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"TAC","type":"text","marks":[{"type":"bold"}]}]},{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"curricular_herramientas_tac","label":"curricular_herramientas_tac"},"marks":[]}]},{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"text":"Inteligencia artificial","type":"text","marks":[{"type":"bold"}]}]},{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"curricular_herramientas_ia","label":"curricular_herramientas_ia"},"marks":[]}]}]},"content_type":"fixed","detached_fields":[]}' WHERE id = '48e52e42-03f9-46e0-baf3-cfe1a87a376f';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"ambientes_aprendizaje"}}]}]},"content_type":"text"}' WHERE id = '069e8adb-b865-4b6d-b1cd-b8c859d3d6ee';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"recuperacion"}}]}]},"content_type":"text"}' WHERE id = '555dfab6-a877-49f8-adea-b7b5a5429c9f';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"field","attrs":{"key":"contribucion_perfil"}}]}]},"content_type":"text"}' WHERE id = 'e6b86d4d-687c-4af9-8764-0de68310b6e3';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"curricular_bibliografia_basica","label":"curricular_bibliografia_basica"},"marks":[]}]}]},"content_type":"fixed","detached_fields":[]}' WHERE id = 'a19fd900-df5a-46e8-be66-fa8b27a9b253';
UPDATE campos_plantilla SET configuracion_campo_plantilla = '{"document":{"type":"doc","content":[{"type":"paragraph","attrs":{"textAlign":"left"},"content":[{"type":"variable","attrs":{"id":"curricular_bibliografia_complementaria","label":"curricular_bibliografia_complementaria"},"marks":[]}]}]},"content_type":"fixed","detached_fields":[]}' WHERE id = '55dd302b-f520-4b09-8fc6-e6c490e5a3c3';
ALTER TABLE secciones_plantilla ADD CONSTRAINT secciones_plantilla_id_plantilla_unica UNIQUE (id, plantilla_id);
ALTER TABLE campos_plantilla ADD CONSTRAINT campos_plantilla_id_plantilla_unica UNIQUE (id, plantilla_id);
ALTER TABLE campos_plantilla ADD CONSTRAINT campos_plantilla_seccion_misma_plantilla_foreign FOREIGN KEY (seccion_plantilla_id, plantilla_id) REFERENCES secciones_plantilla (id, plantilla_id) ON DELETE RESTRICT;
ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_campo_misma_plantilla_foreign FOREIGN KEY (campo_plantilla_id, plantilla_id) REFERENCES campos_plantilla (id, plantilla_id) ON DELETE RESTRICT;

CREATE FUNCTION validar_condicion_definicion() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.mostrar_si_definicion_campo IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM definiciones_campo d
        WHERE d.plantilla_id = NEW.plantilla_id
          AND d.clave_definicion_campo = NEW.mostrar_si_definicion_campo ->> 'key'
          AND d.id <> NEW.id
    ) THEN
        RAISE EXCEPTION 'La condición apunta a un campo que no existe en la plantilla' USING ERRCODE = '23503';
    END IF;
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER validar_condicion_definicion
AFTER INSERT OR UPDATE OF mostrar_si_definicion_campo ON definiciones_campo
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION validar_condicion_definicion();

CREATE FUNCTION propagar_clave_condicion() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        UPDATE definiciones_campo SET mostrar_si_definicion_campo = NULL
        WHERE plantilla_id = OLD.plantilla_id
          AND mostrar_si_definicion_campo ->> 'key' = OLD.clave_definicion_campo;
        RETURN OLD;
    END IF;
    IF NEW.clave_definicion_campo IS DISTINCT FROM OLD.clave_definicion_campo THEN
        UPDATE definiciones_campo
        SET mostrar_si_definicion_campo = jsonb_set(mostrar_si_definicion_campo, '{key}', to_jsonb(NEW.clave_definicion_campo))
        WHERE plantilla_id = OLD.plantilla_id
          AND mostrar_si_definicion_campo ->> 'key' = OLD.clave_definicion_campo;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER propagar_clave_condicion
AFTER DELETE OR UPDATE OF clave_definicion_campo ON definiciones_campo
FOR EACH ROW EXECUTE FUNCTION propagar_clave_condicion();

ALTER TABLE envios_biblioteca ALTER COLUMN enviado_en TYPE timestamp(0) with time zone;
ALTER TABLE envios_biblioteca ALTER COLUMN verificado_en TYPE timestamp(0) with time zone;
ALTER TABLE firmas_documento ALTER COLUMN firmado_en TYPE timestamp(0) with time zone;
ALTER TABLE firmas_documento ALTER COLUMN certificado_vence_en TYPE timestamp(0) with time zone;

-- ===== Registrar las migraciones como aplicadas =====
INSERT INTO migraciones (migration, batch) VALUES
    ('2026_09_30_000166_retire_unused_schema_remains', 80),
    ('2026_09_30_000167_add_missing_integrity_rules', 80),
    ('2026_09_30_000168_tidy_indexes_and_constraint_names', 80),
    ('2026_09_30_000169_rename_ambiguous_columns', 80),
    ('2026_09_30_000170_store_template_fields_once', 80);

COMMIT;
