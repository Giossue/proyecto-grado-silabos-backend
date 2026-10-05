<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convocatorias_universidad', function (Blueprint $table): void {
            $table->timestampTz('pausado_en', 6)->nullable();
        });
        Schema::table('plantilla_silabo', function (Blueprint $table): void {
            $table->timestampTz('plantilla_modificada_en', 6)->nullable();
        });
        Schema::table('secciones_plantilla', function (Blueprint $table): void {
            $table->boolean('vigente_en_plantilla')->default(true);
        });
        Schema::table('campos_plantilla', function (Blueprint $table): void {
            $table->boolean('vigente_en_plantilla')->default(true);
        });
        Schema::table('definiciones_campo', function (Blueprint $table): void {
            $table->boolean('vigente_en_plantilla')->default(true);
        });
        DB::table('convocatorias_universidad')
            ->where('estado_convocatoria_universidad', 'pausado')
            ->update(['pausado_en' => now()]);

        DB::unprepared(<<<'SQL'
            ALTER TABLE secciones_plantilla DROP CONSTRAINT IF EXISTS secciones_plantilla_clave_unica;
            ALTER TABLE campos_plantilla DROP CONSTRAINT IF EXISTS campos_plantilla_clave_unica;
            ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_plantilla_id_clave_campo_unique;
            DROP INDEX IF EXISTS secciones_plantilla_clave_unica;
            DROP INDEX IF EXISTS campos_plantilla_clave_unica;
            DROP INDEX IF EXISTS definiciones_campo_plantilla_id_clave_campo_unique;

            CREATE UNIQUE INDEX secciones_plantilla_vigente_clave_unica
                ON secciones_plantilla (plantilla_id, clave_seccion_plantilla)
                WHERE vigente_en_plantilla;
            CREATE UNIQUE INDEX campos_plantilla_vigente_clave_unica
                ON campos_plantilla (plantilla_id, clave_campo_plantilla)
                WHERE vigente_en_plantilla;
            CREATE UNIQUE INDEX definiciones_campo_vigente_clave_unica
                ON definiciones_campo (plantilla_id, clave_definicion_campo)
                WHERE vigente_en_plantilla;

            CREATE OR REPLACE FUNCTION marcar_cambio_plantilla() RETURNS trigger AS $$
            DECLARE plantilla uuid;
            BEGIN
                plantilla := CASE WHEN TG_OP = 'DELETE' THEN OLD.plantilla_id ELSE NEW.plantilla_id END;
                UPDATE plantilla_silabo
                SET plantilla_modificada_en = clock_timestamp()
                WHERE plantilla_id = plantilla;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER marcar_cambio_seccion_plantilla
                AFTER INSERT OR UPDATE OR DELETE ON secciones_plantilla
                FOR EACH ROW EXECUTE FUNCTION marcar_cambio_plantilla();
            CREATE TRIGGER marcar_cambio_campo_plantilla
                AFTER INSERT OR UPDATE OR DELETE ON campos_plantilla
                FOR EACH ROW EXECUTE FUNCTION marcar_cambio_plantilla();
            CREATE TRIGGER marcar_cambio_definicion_campo_plantilla
                AFTER INSERT OR UPDATE OR DELETE ON definiciones_campo
                FOR EACH ROW EXECUTE FUNCTION marcar_cambio_plantilla();

            CREATE OR REPLACE FUNCTION marcar_mapeo_plantilla_modificado() RETURNS trigger AS $$
            BEGIN
                IF OLD.mapeo_documento_plantilla IS DISTINCT FROM NEW.mapeo_documento_plantilla THEN
                    NEW.plantilla_modificada_en := clock_timestamp();
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER marcar_mapeo_plantilla_modificado
                BEFORE UPDATE OF mapeo_documento_plantilla ON plantilla_silabo
                FOR EACH ROW EXECUTE FUNCTION marcar_mapeo_plantilla_modificado();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('I-188 no admite reversión automática del seguimiento de cambios de plantilla.');
    }
};
