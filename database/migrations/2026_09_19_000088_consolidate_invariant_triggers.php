<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS proteger_revision_ia_silabo_actualizacion ON revisiones_ia_silabo;
            DROP TRIGGER IF EXISTS proteger_revision_ia_silabo_eliminacion ON revisiones_ia_silabo;

            CREATE TRIGGER proteger_revision_ia_silabo
            BEFORE UPDATE OR DELETE ON revisiones_ia_silabo
            FOR EACH ROW EXECUTE FUNCTION proteger_revision_ia_silabo();

            DROP TRIGGER IF EXISTS validar_revision_padre_ejecucion_ia_trigger ON ejecuciones_ia;

            CREATE OR REPLACE FUNCTION validar_ejecucion_ia() RETURNS trigger AS $function$
            DECLARE
                plantilla_silabo uuid;
                plantilla_campo uuid;
                silabo_revision uuid;
                version_revision integer;
            BEGIN
                SELECT plantilla_id INTO plantilla_silabo
                FROM silabos WHERE id = NEW.silabo_id;
                SELECT plantilla_id INTO plantilla_campo
                FROM definiciones_campo WHERE id = NEW.definicion_campo_id;

                IF plantilla_silabo IS DISTINCT FROM NEW.plantilla_id
                   OR plantilla_campo IS DISTINCT FROM NEW.plantilla_id THEN
                    RAISE EXCEPTION 'La ejecución de IA no coincide con la plantilla y campo del sílabo' USING ERRCODE = '23514';
                END IF;

                IF NEW.revision_ia_silabo_id IS NOT NULL THEN
                    SELECT silabo_id, version_bloqueo_origen
                    INTO silabo_revision, version_revision
                    FROM revisiones_ia_silabo
                    WHERE id = NEW.revision_ia_silabo_id;

                    IF silabo_revision IS DISTINCT FROM NEW.silabo_id
                       OR version_revision IS DISTINCT FROM NEW.version_bloqueo_origen THEN
                        RAISE EXCEPTION 'La ejecución no pertenece a la revisión integral indicada' USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'UPDATE'
                       AND NEW.revision_ia_silabo_id IS DISTINCT FROM OLD.revision_ia_silabo_id THEN
                        RAISE EXCEPTION 'La revisión integral asociada a una ejecución es inmutable' USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF OLD.estado_ejecucion_ia IN ('completada', 'no_concluyente', 'fallida') THEN
                        RAISE EXCEPTION 'Una ejecución de IA terminal es inmutable' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.id IS DISTINCT FROM OLD.id
                       OR NEW.silabo_id IS DISTINCT FROM OLD.silabo_id
                       OR NEW.definicion_campo_id IS DISTINCT FROM OLD.definicion_campo_id
                       OR NEW.plantilla_id IS DISTINCT FROM OLD.plantilla_id
                       OR NEW.clave_idempotencia IS DISTINCT FROM OLD.clave_idempotencia
                       OR NEW.clave_funcional IS DISTINCT FROM OLD.clave_funcional
                       OR NEW.version_contrato IS DISTINCT FROM OLD.version_contrato
                       OR NEW.version_instruccion IS DISTINCT FROM OLD.version_instruccion
                       OR NEW.version_pasarela_solicitada IS DISTINCT FROM OLD.version_pasarela_solicitada
                       OR NEW.idioma IS DISTINCT FROM OLD.idioma
                       OR NEW.contenido_entrada_ia IS DISTINCT FROM OLD.contenido_entrada_ia
                       OR NEW.huella_contenido IS DISTINCT FROM OLD.huella_contenido
                       OR NEW.huella_conjunto_fuentes IS DISTINCT FROM OLD.huella_conjunto_fuentes
                       OR NEW.metadatos_entrada_ia IS DISTINCT FROM OLD.metadatos_entrada_ia
                       OR NEW.version_bloqueo_origen IS DISTINCT FROM OLD.version_bloqueo_origen
                       OR NEW.solicitado_por IS DISTINCT FROM OLD.solicitado_por
                       OR NEW.asignacion_rol_id IS DISTINCT FROM OLD.asignacion_rol_id
                       OR NEW.solicitado_en IS DISTINCT FROM OLD.solicitado_en THEN
                        RAISE EXCEPTION 'La entrada fijada de una ejecución de IA es inmutable' USING ERRCODE = '23514';
                    END IF;
                    IF OLD.ejecucion_trabajo_id IS NOT NULL
                       AND NEW.ejecucion_trabajo_id IS DISTINCT FROM OLD.ejecucion_trabajo_id THEN
                        RAISE EXCEPTION 'El trabajo asociado a la ejecución de IA es inmutable' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $function$ LANGUAGE plpgsql;

            DROP FUNCTION IF EXISTS validar_revision_padre_ejecucion_ia();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS proteger_revision_ia_silabo ON revisiones_ia_silabo;

            CREATE TRIGGER proteger_revision_ia_silabo_actualizacion
            BEFORE UPDATE ON revisiones_ia_silabo
            FOR EACH ROW EXECUTE FUNCTION proteger_revision_ia_silabo();

            CREATE TRIGGER proteger_revision_ia_silabo_eliminacion
            BEFORE DELETE ON revisiones_ia_silabo
            FOR EACH ROW EXECUTE FUNCTION proteger_revision_ia_silabo();

            DROP TRIGGER IF EXISTS validar_y_proteger_ejecucion_ia ON ejecuciones_ia;

            CREATE OR REPLACE FUNCTION validar_ejecucion_ia() RETURNS trigger AS $function$
            DECLARE
                plantilla_silabo uuid;
                plantilla_campo uuid;
            BEGIN
                SELECT plantilla_id INTO plantilla_silabo
                FROM silabos WHERE id = NEW.silabo_id;
                SELECT plantilla_id INTO plantilla_campo
                FROM definiciones_campo WHERE id = NEW.definicion_campo_id;

                IF plantilla_silabo IS DISTINCT FROM NEW.plantilla_id
                   OR plantilla_campo IS DISTINCT FROM NEW.plantilla_id THEN
                    RAISE EXCEPTION 'La ejecución de IA no coincide con la plantilla y campo del sílabo' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF OLD.estado_ejecucion_ia IN ('completada', 'no_concluyente', 'fallida') THEN
                        RAISE EXCEPTION 'Una ejecución de IA terminal es inmutable' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.id IS DISTINCT FROM OLD.id
                       OR NEW.silabo_id IS DISTINCT FROM OLD.silabo_id
                       OR NEW.definicion_campo_id IS DISTINCT FROM OLD.definicion_campo_id
                       OR NEW.plantilla_id IS DISTINCT FROM OLD.plantilla_id
                       OR NEW.clave_idempotencia IS DISTINCT FROM OLD.clave_idempotencia
                       OR NEW.clave_funcional IS DISTINCT FROM OLD.clave_funcional
                       OR NEW.version_contrato IS DISTINCT FROM OLD.version_contrato
                       OR NEW.version_instruccion IS DISTINCT FROM OLD.version_instruccion
                       OR NEW.version_pasarela_solicitada IS DISTINCT FROM OLD.version_pasarela_solicitada
                       OR NEW.idioma IS DISTINCT FROM OLD.idioma
                       OR NEW.contenido_entrada_ia IS DISTINCT FROM OLD.contenido_entrada_ia
                       OR NEW.huella_contenido IS DISTINCT FROM OLD.huella_contenido
                       OR NEW.huella_conjunto_fuentes IS DISTINCT FROM OLD.huella_conjunto_fuentes
                       OR NEW.metadatos_entrada_ia IS DISTINCT FROM OLD.metadatos_entrada_ia
                       OR NEW.version_bloqueo_origen IS DISTINCT FROM OLD.version_bloqueo_origen
                       OR NEW.solicitado_por IS DISTINCT FROM OLD.solicitado_por
                       OR NEW.asignacion_rol_id IS DISTINCT FROM OLD.asignacion_rol_id
                       OR NEW.solicitado_en IS DISTINCT FROM OLD.solicitado_en THEN
                        RAISE EXCEPTION 'La entrada fijada de una ejecución de IA es inmutable' USING ERRCODE = '23514';
                    END IF;
                    IF OLD.ejecucion_trabajo_id IS NOT NULL
                       AND NEW.ejecucion_trabajo_id IS DISTINCT FROM OLD.ejecucion_trabajo_id THEN
                        RAISE EXCEPTION 'El trabajo asociado a la ejecución de IA es inmutable' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $function$ LANGUAGE plpgsql;

            CREATE TRIGGER validar_y_proteger_ejecucion_ia
            BEFORE INSERT OR UPDATE ON ejecuciones_ia
            FOR EACH ROW EXECUTE FUNCTION validar_ejecucion_ia();

            CREATE OR REPLACE FUNCTION validar_revision_padre_ejecucion_ia() RETURNS trigger AS $function$
            DECLARE
                silabo_revision uuid;
                version_revision integer;
            BEGIN
                IF NEW.revision_ia_silabo_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT silabo_id, version_bloqueo_origen
                INTO silabo_revision, version_revision
                FROM revisiones_ia_silabo
                WHERE id = NEW.revision_ia_silabo_id;

                IF silabo_revision IS DISTINCT FROM NEW.silabo_id
                   OR version_revision IS DISTINCT FROM NEW.version_bloqueo_origen THEN
                    RAISE EXCEPTION 'La ejecución no pertenece a la revisión integral indicada' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE'
                   AND NEW.revision_ia_silabo_id IS DISTINCT FROM OLD.revision_ia_silabo_id THEN
                    RAISE EXCEPTION 'La revisión integral asociada a una ejecución es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $function$ LANGUAGE plpgsql;

            CREATE TRIGGER validar_revision_padre_ejecucion_ia_trigger
            BEFORE INSERT OR UPDATE ON ejecuciones_ia
            FOR EACH ROW EXECUTE FUNCTION validar_revision_padre_ejecucion_ia();
            SQL);
    }
};
