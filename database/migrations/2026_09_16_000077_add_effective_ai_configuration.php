<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantillas_silabo', function (Blueprint $table) {
            $table->unsignedBigInteger('revision_configuracion')->default(1);
        });
        Schema::create('preferencias_ia_carrera', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('carrera_id')->constrained('carreras')->restrictOnDelete();
            $table->foreignUuid('definicion_campo_id')->constrained('definiciones_campo')->restrictOnDelete();
            $table->boolean('ia_habilitada');
            $table->unsignedBigInteger('revision_plantilla_origen');
            $table->foreignUuid('configurado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('configurado_en');
            $table->unique(['carrera_id', 'definicion_campo_id'], 'preferencia_ia_carrera_campo_unica');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tocar_revision_configuracion_plantilla()
            RETURNS trigger AS $$
            DECLARE plantilla uuid;
            BEGIN
                plantilla := CASE WHEN TG_OP = 'DELETE' THEN OLD.plantilla_id ELSE NEW.plantilla_id END;
                UPDATE plantillas_silabo
                SET revision_configuracion = revision_configuracion + 1
                WHERE id = plantilla;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tocar_revision_por_seccion
            AFTER INSERT OR UPDATE OR DELETE ON secciones_plantilla
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();
            CREATE TRIGGER tocar_revision_por_bloque
            AFTER INSERT OR UPDATE OR DELETE ON bloques_plantilla
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();
            CREATE TRIGGER tocar_revision_por_campo
            AFTER INSERT OR UPDATE OR DELETE ON definiciones_campo
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();

            CREATE OR REPLACE FUNCTION tocar_revision_configuracion_plantilla_directa()
            RETURNS trigger AS $$
            BEGIN
                IF ROW(OLD.nombre_plantilla_silabo, OLD.descripcion_plantilla_silabo, OLD.plantilla_silabo_activa, OLD.mapeo_documento_plantilla)
                   IS DISTINCT FROM
                   ROW(NEW.nombre_plantilla_silabo, NEW.descripcion_plantilla_silabo, NEW.plantilla_silabo_activa, NEW.mapeo_documento_plantilla) THEN
                    NEW.revision_configuracion := OLD.revision_configuracion + 1;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER tocar_revision_plantilla_directa
            BEFORE UPDATE ON plantillas_silabo
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla_directa();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS tocar_revision_plantilla_directa ON plantillas_silabo;
            DROP TRIGGER IF EXISTS tocar_revision_por_campo ON definiciones_campo;
            DROP TRIGGER IF EXISTS tocar_revision_por_bloque ON bloques_plantilla;
            DROP TRIGGER IF EXISTS tocar_revision_por_seccion ON secciones_plantilla;
            DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla_directa();
            DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla();
            SQL);
        Schema::dropIfExists('preferencias_ia_carrera');
        Schema::table('plantillas_silabo', function (Blueprint $table) {
            $table->dropColumn('revision_configuracion');
        });
    }
};
