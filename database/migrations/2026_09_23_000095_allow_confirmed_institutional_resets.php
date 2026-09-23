<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reinicio_institucional_habilitado() RETURNS boolean AS $$
            BEGIN
                RETURN current_setting('app.reinicio_institucional', true) = 'on';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION proteger_registro_append_only() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                RAISE EXCEPTION 'El registro histórico es inmutable' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION proteger_contenido_observacion() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE'
                   OR NEW.revision_silabo_id IS DISTINCT FROM OLD.revision_silabo_id
                   OR NEW.clave_seccion IS DISTINCT FROM OLD.clave_seccion
                   OR NEW.clave_campo IS DISTINCT FROM OLD.clave_campo
                   OR NEW.contenido_observacion_revision IS DISTINCT FROM OLD.contenido_observacion_revision
                   OR NEW.creado_por IS DISTINCT FROM OLD.creado_por
                   OR NEW.observado_en IS DISTINCT FROM OLD.observado_en THEN
                    RAISE EXCEPTION 'El contenido de la observación es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION proteger_respuesta_enviada() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' OR OLD.revision_respuesta_id IS NOT NULL THEN
                    RAISE EXCEPTION 'La respuesta enviada es inmutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.silabo_id IS DISTINCT FROM OLD.silabo_id
                   OR NEW.observacion_revision_id IS DISTINCT FROM OLD.observacion_revision_id THEN
                    RAISE EXCEPTION 'No se puede cambiar la identidad de la respuesta' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION proteger_revision_ia_silabo() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una revisión integral de IA no puede eliminarse' USING ERRCODE = '23514';
                END IF;
                IF OLD.estado_revision_ia IN ('completada', 'no_concluyente', 'parcial', 'fallida') THEN
                    RAISE EXCEPTION 'Una revisión integral de IA terminal es inmutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id
                   OR NEW.silabo_id IS DISTINCT FROM OLD.silabo_id
                   OR NEW.version_bloqueo_origen IS DISTINCT FROM OLD.version_bloqueo_origen
                   OR NEW.clave_idempotencia IS DISTINCT FROM OLD.clave_idempotencia
                   OR NEW.total_campos IS DISTINCT FROM OLD.total_campos
                   OR NEW.solicitado_por IS DISTINCT FROM OLD.solicitado_por
                   OR NEW.asignacion_rol_id IS DISTINCT FROM OLD.asignacion_rol_id
                   OR NEW.solicitado_en IS DISTINCT FROM OLD.solicitado_en THEN
                    RAISE EXCEPTION 'La entrada de una revisión integral de IA es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION validar_artefacto_exportacion() RETURNS trigger AS $$
            DECLARE
                silabo_revision uuid;
                plantilla_silabo uuid;
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un artefacto de exportación no puede eliminarse' USING ERRCODE = '23514';
                END IF;
                SELECT r.silabo_id, s.plantilla_id
                INTO silabo_revision, plantilla_silabo
                FROM revisiones_silabo r
                JOIN silabos s ON s.id = r.silabo_id
                WHERE r.id = NEW.revision_silabo_id;
                IF silabo_revision IS DISTINCT FROM NEW.silabo_id
                   OR plantilla_silabo IS DISTINCT FROM NEW.plantilla_id THEN
                    RAISE EXCEPTION 'El artefacto no coincide con la revisión y plantilla del sílabo' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.estado_artefacto_exportacion = 'completado' AND NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION 'Un artefacto completado es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION proteger_notificacion_interna() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE'
                   OR NEW.usuario_id IS DISTINCT FROM OLD.usuario_id
                   OR NEW.clave_deduplicacion IS DISTINCT FROM OLD.clave_deduplicacion
                   OR NEW.tipo_notificacion_interna IS DISTINCT FROM OLD.tipo_notificacion_interna
                   OR NEW.titulo_notificacion_interna IS DISTINCT FROM OLD.titulo_notificacion_interna
                   OR NEW.mensaje_notificacion_interna IS DISTINCT FROM OLD.mensaje_notificacion_interna
                   OR NEW.tipo_recurso IS DISTINCT FROM OLD.tipo_recurso
                   OR NEW.recurso_id IS DISTINCT FROM OLD.recurso_id
                   OR NEW.notificado_en IS DISTINCT FROM OLD.notificado_en THEN
                    RAISE EXCEPTION 'El contenido de la notificación es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('El permiso transaccional de reinicio institucional forma parte del invariante vigente.');
    }
};
