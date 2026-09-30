<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS eliminar_documentos_ia_de_fuente ON fuentes_academicas');
        DB::statement('DROP FUNCTION IF EXISTS eliminar_documentos_ia_de_fuente()');
        DB::statement('DROP TABLE IF EXISTS documentos_ia');
        DB::unprepared('DO $$ BEGIN DROP EXTENSION IF EXISTS vector; EXCEPTION WHEN insufficient_privilege OR dependent_objects_still_exist THEN NULL; END $$;');

        DB::statement('DROP TABLE IF EXISTS resultados_aprendizaje_asignatura');
        DB::statement('DROP TABLE IF EXISTS bases_resultados_asignatura');

        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        DB::statement("DELETE FROM historial_ia WHERE mensaje_ia ->> 'tipo' NOT IN ('mensaje', 'ocultamiento') OR sesion_id !~ '^silabo:[0-9a-f-]{36}:usuario:[0-9a-f-]{36}$'");
        DB::statement('RESET app.reinicio_institucional');
        foreach (['historial_ia_analisis_idempotencia_unica', 'historial_ia_aplicada_unica', 'historial_ia_decision_unica', 'historial_ia_revision_version_unica'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
        DB::statement('ALTER TABLE historial_ia DROP CONSTRAINT historial_ia_mensaje_check');
        DB::statement("ALTER TABLE historial_ia ADD CONSTRAINT historial_ia_mensaje_check CHECK (
            jsonb_typeof(mensaje_ia) = 'object'
            AND jsonb_typeof(mensaje_ia -> 'entrada') = 'object'
            AND mensaje_ia ->> 'tipo' IN ('mensaje', 'ocultamiento')
            AND mensaje_ia ->> 'estado' IN ('pendiente', 'en_ejecucion', 'completado', 'fallido', 'registrada')
        )");
        DB::statement('ALTER TABLE historial_ia DROP CONSTRAINT historial_ia_sesion_check');
        DB::statement("ALTER TABLE historial_ia ADD CONSTRAINT historial_ia_sesion_check CHECK (sesion_id ~ '^silabo:[0-9a-f-]{36}:usuario:[0-9a-f-]{36}$')");

        DB::statement('DROP TRIGGER IF EXISTS validar_solicitud_correccion ON solicitudes_correccion');

        DB::unprepared(<<<'SQL'
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
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS tocar_revision_plantilla_directa ON plantilla_silabo');
        DB::statement('DROP TRIGGER IF EXISTS tocar_revision_por_campo ON campos_plantilla');
        DB::statement('DROP TRIGGER IF EXISTS tocar_revision_por_campo ON definiciones_campo');
        DB::statement('DROP TRIGGER IF EXISTS tocar_revision_por_seccion ON secciones_plantilla');
        DB::statement('DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla()');
        DB::statement('DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla_directa()');
        DB::statement('ALTER TABLE plantilla_silabo DROP COLUMN IF EXISTS revision_configuracion');

        DB::statement('ALTER TABLE definiciones_campo DROP COLUMN IF EXISTS ia_habilitada');
        DB::statement('ALTER TABLE definiciones_campo DROP COLUMN IF EXISTS marcador_documento');
        DB::statement('ALTER TABLE valores_campo DROP COLUMN IF EXISTS heredado');
        DB::statement('ALTER TABLE valores_campo DROP COLUMN IF EXISTS origen_valor_campo');
        DB::statement('ALTER TABLE fuentes_academicas DROP COLUMN IF EXISTS contenido_fuente_academica');
        DB::statement('ALTER TABLE fuentes_academicas DROP COLUMN IF EXISTS version_documento_fuente');
        DB::statement('ALTER TABLE fuentes_academicas DROP COLUMN IF EXISTS descripcion_fuente_academica');
        DB::statement('ALTER TABLE artefactos_exportacion DROP COLUMN IF EXISTS asignacion_rol_id');

        DB::table('migraciones')->where('migration', '2026_08_14_000009_create_institutional_import_tables')->delete();
    }

    public function down(): void
    {
        throw new LogicException('La retirada de restos del esquema (I-168) no se revierte: restaure el respaldo previo.');
    }
};
