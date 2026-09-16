<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisiones_ia_silabo', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('silabo_id')->constrained('silabos')->restrictOnDelete();
            $table->unsignedInteger('version_bloqueo_origen');
            $table->uuid('clave_idempotencia');
            $table->string('estado_revision_ia', 24)->default('pendiente');
            $table->unsignedSmallInteger('total_campos');
            $table->unsignedSmallInteger('campos_finalizados')->default(0);
            $table->unsignedSmallInteger('campos_con_recomendaciones')->default(0);
            $table->unsignedSmallInteger('campos_fallidos')->default(0);
            $table->foreignUuid('solicitado_por')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('asignacion_rol_id')->nullable()->constrained('asignaciones_rol')->restrictOnDelete();
            $table->timestampTz('solicitado_en');
            $table->timestampTz('iniciado_en')->nullable();
            $table->timestampTz('completado_en')->nullable();

            $table->unique(['silabo_id', 'version_bloqueo_origen'], 'revision_ia_silabo_version_unica');
            $table->unique(['silabo_id', 'clave_idempotencia'], 'revision_ia_silabo_idempotencia_unica');
            $table->index(['silabo_id', 'solicitado_en'], 'revision_ia_silabo_historial_idx');
            $table->index(['estado_revision_ia', 'solicitado_en'], 'revision_ia_silabo_estado_idx');
        });

        Schema::table('ejecuciones_ia', function (Blueprint $table): void {
            $table->foreignUuid('revision_ia_silabo_id')
                ->nullable()
                ->constrained('revisiones_ia_silabo')
                ->restrictOnDelete();
            $table->unique(
                ['revision_ia_silabo_id', 'definicion_campo_id'],
                'revision_ia_silabo_campo_unico',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE revisiones_ia_silabo
            ADD CONSTRAINT revision_ia_silabo_estado_check
            CHECK (estado_revision_ia IN ('pendiente', 'en_ejecucion', 'completada', 'no_concluyente', 'parcial', 'fallida'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE revisiones_ia_silabo
            ADD CONSTRAINT revision_ia_silabo_conteos_check
            CHECK (
                total_campos > 0
                AND campos_finalizados <= total_campos
                AND campos_con_recomendaciones <= campos_finalizados
                AND campos_fallidos <= campos_finalizados
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE revisiones_ia_silabo
            ADD CONSTRAINT revision_ia_silabo_terminal_check
            CHECK (
                (estado_revision_ia IN ('completada', 'no_concluyente', 'parcial', 'fallida')
                    AND campos_finalizados = total_campos
                    AND completado_en IS NOT NULL)
                OR
                (estado_revision_ia IN ('pendiente', 'en_ejecucion') AND completado_en IS NULL)
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_revision_ia_silabo() RETURNS trigger AS $$
            BEGIN
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

            CREATE TRIGGER proteger_revision_ia_silabo_actualizacion
            BEFORE UPDATE ON revisiones_ia_silabo
            FOR EACH ROW EXECUTE FUNCTION proteger_revision_ia_silabo();

            CREATE TRIGGER proteger_revision_ia_silabo_eliminacion
            BEFORE DELETE ON revisiones_ia_silabo
            FOR EACH ROW EXECUTE FUNCTION proteger_revision_ia_silabo();

            CREATE OR REPLACE FUNCTION validar_revision_padre_ejecucion_ia() RETURNS trigger AS $$
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
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER validar_revision_padre_ejecucion_ia_trigger
            BEFORE INSERT OR UPDATE OF revision_ia_silabo_id ON ejecuciones_ia
            FOR EACH ROW EXECUTE FUNCTION validar_revision_padre_ejecucion_ia();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS validar_revision_padre_ejecucion_ia_trigger ON ejecuciones_ia;
            DROP FUNCTION IF EXISTS validar_revision_padre_ejecucion_ia();
            DROP TRIGGER IF EXISTS proteger_revision_ia_silabo_actualizacion ON revisiones_ia_silabo;
            DROP TRIGGER IF EXISTS proteger_revision_ia_silabo_eliminacion ON revisiones_ia_silabo;
            DROP FUNCTION IF EXISTS proteger_revision_ia_silabo();
            SQL);
        Schema::table('ejecuciones_ia', function (Blueprint $table): void {
            $table->dropUnique('revision_ia_silabo_campo_unico');
            $table->dropConstrainedForeignId('revision_ia_silabo_id');
        });
        Schema::dropIfExists('revisiones_ia_silabo');
    }
};
