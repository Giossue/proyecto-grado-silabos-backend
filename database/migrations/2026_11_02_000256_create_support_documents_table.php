<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_apoyo', function (Blueprint $table): void {
            $table->uuid('documento_apoyo_id')->primary();
            $table->uuid('carrera_id');
            $table->string('titulo_documento', 180);
            $table->uuid('archivo_id')->unique();
            $table->uuid('archivo_vista_id')->nullable();
            $table->string('estado_vista', 20)->default('pendiente');
            $table->uuid('subido_por');
            $table->timestampTz('subido_en', 0)->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->foreign('carrera_id')->references('carrera_id')->on('carreras')->restrictOnDelete();
            $table->foreign('archivo_id')->references('archivo_id')->on('archivos')->restrictOnDelete();
            $table->foreign('archivo_vista_id')->references('archivo_id')->on('archivos')->restrictOnDelete();
            $table->foreign('subido_por')->references('usuario_id')->on('usuarios')->restrictOnDelete();
            $table->index(['carrera_id', 'subido_en']);
            $table->index('archivo_vista_id');
            $table->index('subido_por');
        });
        DB::statement("ALTER TABLE documentos_apoyo ADD CONSTRAINT documentos_apoyo_estado_vista_check CHECK (estado_vista IN ('pendiente', 'lista', 'sin_vista'))");
        DB::statement("ALTER TABLE documentos_apoyo ADD CONSTRAINT documentos_apoyo_vista_check CHECK (estado_vista <> 'lista' OR archivo_vista_id IS NOT NULL)");
        DB::statement("ALTER TABLE documentos_apoyo ADD CONSTRAINT documentos_apoyo_titulo_check CHECK (btrim(titulo_documento) <> '')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_archivo_registrado() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.categoria IN ('documento_apoyo', 'documento_apoyo_vista') THEN
                    RETURN OLD;
                END IF;
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                RAISE EXCEPTION 'El registro histórico es inmutable' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS proteger_archivo ON archivos;
            CREATE TRIGGER proteger_archivo
            BEFORE DELETE OR UPDATE ON archivos
            FOR EACH ROW EXECUTE FUNCTION proteger_archivo_registrado();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS proteger_archivo ON archivos;
            CREATE TRIGGER proteger_archivo
            BEFORE DELETE OR UPDATE ON archivos
            FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only();
            DROP FUNCTION IF EXISTS proteger_archivo_registrado();
            SQL);
        Schema::dropIfExists('documentos_apoyo');
    }
};
