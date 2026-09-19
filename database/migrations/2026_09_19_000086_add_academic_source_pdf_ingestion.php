<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuentes_academicas', function (Blueprint $table): void {
            $table->foreignUuid('objeto_pdf_id')
                ->nullable()
                ->after('contenido_fuente_academica')
                ->constrained('objetos_almacenados')
                ->restrictOnDelete()
                ->unique('fuentes_academicas_objeto_pdf_unico');
            $table->string('estado_ingesta_fuente', 24)
                ->default('manual')
                ->after('objeto_pdf_id');
            $table->timestampTz('ingesta_actualizada_en')
                ->nullable()
                ->after('estado_ingesta_fuente');
        });

        Schema::create('extracciones_fuente_academica', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('fuente_academica_id')
                ->constrained('fuentes_academicas')
                ->restrictOnDelete();
            $table->foreignUuid('objeto_pdf_id')
                ->constrained('objetos_almacenados')
                ->restrictOnDelete();
            $table->foreignUuid('ejecucion_trabajo_id')
                ->nullable()
                ->unique('extraccion_fuente_ejecucion_unica')
                ->constrained('ejecuciones_trabajo')
                ->restrictOnDelete();
            $table->foreignUuid('solicitada_por')
                ->constrained('usuarios')
                ->restrictOnDelete();
            $table->string('estado_extraccion_fuente', 24)->default('pendiente');
            $table->string('modelo_ocr', 100);
            $table->string('version_adaptador', 80);
            $table->char('huella_pdf', 64);
            $table->jsonb('opciones_extraccion')->nullable();
            $table->jsonb('paginas_extraidas')->nullable();
            $table->jsonb('metadatos_respuesta')->nullable();
            $table->char('huella_contenido', 64)->nullable();
            $table->string('codigo_error', 100)->nullable();
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('solicitada_en');
            $table->timestampTz('iniciada_en')->nullable();
            $table->timestampTz('completada_en')->nullable();
            $table->timestampTz('fallida_en')->nullable();

            $table->unique(
                ['fuente_academica_id', 'huella_pdf', 'modelo_ocr'],
                'extraccion_fuente_idempotencia_unica',
            );
            $table->index(['fuente_academica_id', 'estado_extraccion_fuente', 'solicitada_en']);
        });

        DB::statement(
            "ALTER TABLE fuentes_academicas
             ADD CONSTRAINT fuentes_academicas_estado_ingesta_check
             CHECK (estado_ingesta_fuente IN ('manual', 'pendiente', 'en_ejecucion', 'completada', 'fallida'))",
        );
        DB::statement(
            "ALTER TABLE extracciones_fuente_academica
             ADD CONSTRAINT extracciones_fuente_estado_check
             CHECK (estado_extraccion_fuente IN ('pendiente', 'en_ejecucion', 'completada', 'fallida'))",
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE extracciones_fuente_academica DROP CONSTRAINT IF EXISTS extracciones_fuente_estado_check');
        DB::statement('ALTER TABLE fuentes_academicas DROP CONSTRAINT IF EXISTS fuentes_academicas_estado_ingesta_check');
        Schema::dropIfExists('extracciones_fuente_academica');
        Schema::table('fuentes_academicas', function (Blueprint $table): void {
            $table->dropForeign(['objeto_pdf_id']);
            $table->dropUnique('fuentes_academicas_objeto_pdf_unico');
            $table->dropColumn(['objeto_pdf_id', 'estado_ingesta_fuente', 'ingesta_actualizada_en']);
        });
    }
};
