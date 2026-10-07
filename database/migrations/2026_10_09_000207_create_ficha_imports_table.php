<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I-204: asistente del Proyecto de carrera. Coordinación sube los documentos de su carrera (PDF o
 * Excel); un trabajo propone la ficha de cada materia y Coordinación confirma qué aplicar.
 * Los archivos son temporales: se borran al aplicar o descartar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importaciones_ficha', function (Blueprint $table): void {
            $table->uuid('importacion_ficha_id')->primary();
            $table->uuid('carrera_id');
            $table->uuid('solicitado_por');
            $table->string('estado_importacion', 20);
            $table->jsonb('documentos_importacion');
            $table->jsonb('propuesta_importacion')->nullable();
            $table->text('mensaje_error_importacion')->nullable();
            $table->timestampTz('solicitado_en');
            $table->timestampTz('aplicado_en')->nullable();

            $table->foreign('carrera_id')->references('carrera_id')->on('carreras')->restrictOnDelete();
            $table->foreign('solicitado_por')->references('usuario_id')->on('usuarios')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE importaciones_ficha ADD CONSTRAINT importaciones_ficha_estado_check CHECK (estado_importacion IN ('pendiente', 'en_ejecucion', 'lista', 'fallida', 'aplicada', 'descartada'))");
        DB::statement('CREATE INDEX importaciones_ficha_carrera_estado_index ON importaciones_ficha (carrera_id, estado_importacion)');
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones_ficha');
    }
};
