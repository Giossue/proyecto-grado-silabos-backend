<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conceptos_curriculares', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('clave_concepto', 100)->unique();
            $table->string('etiqueta_concepto', 180);
            $table->string('tipo_concepto', 12);
            $table->string('ambito_concepto', 16);
            $table->jsonb('columnas_concepto')->nullable();
        });
        DB::statement("ALTER TABLE conceptos_curriculares ADD CONSTRAINT conceptos_curriculares_tipo_valido CHECK (tipo_concepto IN ('texto', 'lista', 'tabla'))");
        DB::statement("ALTER TABLE conceptos_curriculares ADD CONSTRAINT conceptos_curriculares_ambito_valido CHECK (ambito_concepto IN ('carrera', 'asignatura'))");
        DB::statement("ALTER TABLE conceptos_curriculares ADD CONSTRAINT conceptos_curriculares_columnas_validas CHECK ((tipo_concepto = 'tabla' AND columnas_concepto IS NOT NULL AND jsonb_typeof(columnas_concepto) = 'array' AND jsonb_array_length(columnas_concepto) > 0) OR (tipo_concepto <> 'tabla' AND columnas_concepto IS NULL))");
        Schema::table('asignaturas', function (Blueprint $table): void {
            $table->unique(['id', 'carrera_id'], 'asignaturas_identidad_carrera_unica');
        });
        Schema::create('valores_curriculares', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('concepto_id')->index()->constrained('conceptos_curriculares')->restrictOnDelete();
            $table->foreignUuid('carrera_id')->index()->constrained('carreras')->restrictOnDelete();
            $table->uuid('asignatura_id')->nullable();
            $table->index(['asignatura_id', 'carrera_id']);
            $table->foreign(['asignatura_id', 'carrera_id'], 'valores_curriculares_asignatura_carrera_fk')
                ->references(['id', 'carrera_id'])->on('asignaturas')->restrictOnDelete();
            $table->jsonb('valor_curricular');
            $table->jsonb('evidencia_curricular');
            $table->foreignUuid('confirmado_por')->index()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('confirmado_en');
        });
        DB::statement('CREATE UNIQUE INDEX valores_curriculares_carrera_unico ON valores_curriculares (concepto_id, carrera_id) WHERE asignatura_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX valores_curriculares_asignatura_unico ON valores_curriculares (concepto_id, asignatura_id) WHERE asignatura_id IS NOT NULL');
        DB::statement("ALTER TABLE valores_curriculares ADD CONSTRAINT valores_curriculares_evidencia_valida CHECK (jsonb_typeof(evidencia_curricular) = 'array' AND jsonb_array_length(evidencia_curricular) > 0)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_forma_concepto_curricular() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.clave_concepto IS DISTINCT FROM OLD.clave_concepto
                    OR NEW.tipo_concepto IS DISTINCT FROM OLD.tipo_concepto
                    OR NEW.ambito_concepto IS DISTINCT FROM OLD.ambito_concepto
                    OR NEW.columnas_concepto IS DISTINCT FROM OLD.columnas_concepto THEN
                    RAISE EXCEPTION 'La identidad y estructura del concepto curricular son inmutables' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER conceptos_curriculares_forma_inmutable BEFORE UPDATE ON conceptos_curriculares
                FOR EACH ROW EXECUTE FUNCTION proteger_forma_concepto_curricular();
            CREATE OR REPLACE FUNCTION validar_ambito_valor_curricular() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE ambito text; tipo text;
            BEGIN
                SELECT ambito_concepto, tipo_concepto INTO ambito, tipo FROM conceptos_curriculares WHERE id = NEW.concepto_id;
                IF (ambito = 'carrera' AND NEW.asignatura_id IS NOT NULL)
                    OR (ambito = 'asignatura' AND NEW.asignatura_id IS NULL) THEN
                    RAISE EXCEPTION 'El valor no corresponde al ámbito del concepto curricular' USING ERRCODE = '23514';
                END IF;
                IF (tipo = 'texto' AND jsonb_typeof(NEW.valor_curricular) <> 'string')
                    OR (tipo IN ('lista', 'tabla') AND jsonb_typeof(NEW.valor_curricular) <> 'array') THEN
                    RAISE EXCEPTION 'El valor no corresponde al tipo del concepto curricular' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER valores_curriculares_ambito_valido BEFORE INSERT OR UPDATE ON valores_curriculares
                FOR EACH ROW EXECUTE FUNCTION validar_ambito_valor_curricular();
            SQL);
        Schema::create('importaciones_curriculares', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('carrera_id')->index()->constrained('carreras')->restrictOnDelete();
            $table->foreignUuid('solicitado_por')->index()->constrained('usuarios')->restrictOnDelete();
            $table->string('estado_importacion', 20)->default('pendiente');
            $table->char('clave_idempotencia_importacion', 64)->nullable();
            $table->unique(['carrera_id', 'clave_idempotencia_importacion'], 'importaciones_curriculares_idempotencia_unica');
            $table->jsonb('documentos_importacion');
            $table->jsonb('propuesta_importacion')->nullable();
            $table->jsonb('seleccion_aplicada')->nullable();
            $table->text('instrucciones_importacion')->nullable();
            $table->text('mensaje_error_importacion')->nullable();
            $table->timestampTz('solicitado_en');
            $table->timestampTz('aplicado_en')->nullable();
            $table->index(['carrera_id', 'solicitado_en']);
        });
        DB::statement("ALTER TABLE importaciones_curriculares ADD CONSTRAINT importaciones_curriculares_estado_valido CHECK (estado_importacion IN ('pendiente', 'en_ejecucion', 'lista', 'aplicada', 'fallida'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones_curriculares');
        Schema::dropIfExists('valores_curriculares');
        Schema::dropIfExists('conceptos_curriculares');
        DB::statement('DROP FUNCTION IF EXISTS validar_ambito_valor_curricular()');
        DB::statement('DROP FUNCTION IF EXISTS proteger_forma_concepto_curricular()');
        Schema::table('asignaturas', fn (Blueprint $table) => $table->dropUnique('asignaturas_identidad_carrera_unica'));
    }
};
