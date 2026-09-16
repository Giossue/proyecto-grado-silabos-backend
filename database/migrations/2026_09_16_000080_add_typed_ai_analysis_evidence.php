<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidencias_ia', function (Blueprint $table) {
            $table->renameColumn('nombre_fuente', 'etiqueta_evidencia_ia');
            $table->string('tipo_evidencia_ia', 24)->default('fuente_academica');
            $table->foreignUuid('definicion_campo_id')->nullable()->constrained('definiciones_campo')->restrictOnDelete();
            $table->foreignUuid('fragmento_fuente_academica_id')->nullable()->constrained('fragmentos_fuente_academica')->nullOnDelete();
        });
        DB::statement('ALTER TABLE evidencias_ia ALTER COLUMN fuente_academica_id DROP NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE evidencias_ia
            ADD CONSTRAINT evidencia_ia_tipo_check
            CHECK (tipo_evidencia_ia IN ('fuente_academica', 'seccion_silabo'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE evidencias_ia
            ADD CONSTRAINT evidencia_ia_referencia_check
            CHECK (
                (tipo_evidencia_ia = 'fuente_academica' AND fuente_academica_id IS NOT NULL AND definicion_campo_id IS NULL)
                OR
                (tipo_evidencia_ia = 'seccion_silabo' AND definicion_campo_id IS NOT NULL AND fuente_academica_id IS NULL AND fragmento_fuente_academica_id IS NULL)
            )
            SQL);
        $this->createValidationFunction();
    }

    public function down(): void
    {
        DB::table('recomendacion_evidencias_ia')
            ->whereIn('evidencia_ia_id', DB::table('evidencias_ia')->where('tipo_evidencia_ia', 'seccion_silabo')->select('id'))
            ->delete();
        DB::table('evidencias_ia')->where('tipo_evidencia_ia', 'seccion_silabo')->delete();
        DB::statement('ALTER TABLE evidencias_ia DROP CONSTRAINT IF EXISTS evidencia_ia_referencia_check');
        DB::statement('ALTER TABLE evidencias_ia DROP CONSTRAINT IF EXISTS evidencia_ia_tipo_check');
        DB::statement('ALTER TABLE evidencias_ia ALTER COLUMN fuente_academica_id SET NOT NULL');
        Schema::table('evidencias_ia', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fragmento_fuente_academica_id');
            $table->dropConstrainedForeignId('definicion_campo_id');
            $table->dropColumn('tipo_evidencia_ia');
            $table->renameColumn('etiqueta_evidencia_ia', 'nombre_fuente');
        });
        $this->createLegacyValidationFunction();
    }

    private function createValidationFunction(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_evidencia_ia() RETURNS trigger AS $$
            DECLARE
                carrera_fuente uuid;
                carrera_silabo uuid;
                fuente_activa boolean;
                convocatoria_silabo uuid;
                estado_ejecucion text;
                plantilla_ejecucion uuid;
                plantilla_campo uuid;
                fuente_fragmento uuid;
            BEGIN
                SELECT s.convocatoria_id, s.carrera_id, e.estado_ejecucion_ia, e.plantilla_id
                INTO convocatoria_silabo, carrera_silabo, estado_ejecucion, plantilla_ejecucion
                FROM ejecuciones_ia e
                JOIN silabos s ON s.id = e.silabo_id
                WHERE e.id = NEW.ejecucion_ia_id;

                IF estado_ejecucion IS DISTINCT FROM 'pendiente' THEN
                    RAISE EXCEPTION 'La evidencia solo puede fijarse mientras la ejecución está pendiente' USING ERRCODE = '23514';
                END IF;

                IF NEW.tipo_evidencia_ia = 'fuente_academica' THEN
                    SELECT fuente_academica_activa, carrera_id INTO fuente_activa, carrera_fuente
                    FROM fuentes_academicas WHERE id = NEW.fuente_academica_id;
                    IF NEW.fragmento_fuente_academica_id IS NOT NULL THEN
                        SELECT fuente_academica_id INTO fuente_fragmento
                        FROM fragmentos_fuente_academica WHERE id = NEW.fragmento_fuente_academica_id;
                    END IF;
                    IF carrera_fuente IS DISTINCT FROM carrera_silabo
                       OR fuente_activa IS DISTINCT FROM TRUE
                       OR (NEW.fragmento_fuente_academica_id IS NOT NULL AND fuente_fragmento IS DISTINCT FROM NEW.fuente_academica_id)
                       OR NOT EXISTS (
                           SELECT 1 FROM fuentes_convocatoria
                           WHERE convocatoria_id = convocatoria_silabo
                             AND fuente_academica_id = NEW.fuente_academica_id
                       ) THEN
                        RAISE EXCEPTION 'La evidencia debe citar una fuente activa fijada por la convocatoria' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.tipo_evidencia_ia = 'seccion_silabo' THEN
                    SELECT plantilla_id INTO plantilla_campo
                    FROM definiciones_campo WHERE id = NEW.definicion_campo_id;
                    IF plantilla_campo IS DISTINCT FROM plantilla_ejecucion THEN
                        RAISE EXCEPTION 'La evidencia interna debe pertenecer a la plantilla del sílabo' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    RAISE EXCEPTION 'Tipo de evidencia no admitido' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    private function createLegacyValidationFunction(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_evidencia_ia() RETURNS trigger AS $$
            DECLARE
                carrera_fuente uuid;
                carrera_silabo uuid;
                fuente_activa boolean;
                convocatoria_silabo uuid;
                estado_ejecucion text;
            BEGIN
                SELECT fuente_academica_activa, carrera_id INTO fuente_activa, carrera_fuente
                FROM fuentes_academicas WHERE id = NEW.fuente_academica_id;
                SELECT s.convocatoria_id, s.carrera_id, e.estado_ejecucion_ia
                INTO convocatoria_silabo, carrera_silabo, estado_ejecucion
                FROM ejecuciones_ia e
                JOIN silabos s ON s.id = e.silabo_id
                WHERE e.id = NEW.ejecucion_ia_id;
                IF carrera_fuente IS DISTINCT FROM carrera_silabo
                   OR fuente_activa IS DISTINCT FROM TRUE
                   OR estado_ejecucion IS DISTINCT FROM 'pendiente'
                   OR NOT EXISTS (
                       SELECT 1 FROM fuentes_convocatoria
                       WHERE convocatoria_id = convocatoria_silabo
                         AND fuente_academica_id = NEW.fuente_academica_id
                   ) THEN
                    RAISE EXCEPTION 'La evidencia debe citar una fuente activa fijada por la convocatoria' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }
};
