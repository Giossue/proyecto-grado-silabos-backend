<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversaciones_ia', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('silabo_id')->constrained('silabos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('ocultada_en')->nullable();
            $table->timestampsTz();

            $table->unique(['silabo_id', 'usuario_id'], 'conversacion_ia_docente_silabo_unica');
        });

        Schema::create('mensajes_ia', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversacion_ia_id')->constrained('conversaciones_ia')->restrictOnDelete();
            $table->uuid('respuesta_a_id')->nullable();
            $table->foreignUuid('ejecucion_trabajo_id')->nullable()->unique()->constrained('ejecuciones_trabajo')->restrictOnDelete();
            $table->uuid('clave_idempotencia')->nullable();
            $table->string('rol_mensaje_ia', 16);
            $table->string('estado_mensaje_ia', 24);
            $table->text('contenido_mensaje_ia');
            $table->unsignedInteger('version_bloqueo_origen');
            $table->string('version_pasarela_ejecutada', 80)->nullable();
            $table->string('codigo_error', 80)->nullable();
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('completado_en')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['conversacion_ia_id', 'clave_idempotencia'],
                'mensaje_ia_idempotencia_unica',
            );
            $table->index(['conversacion_ia_id', 'created_at'], 'mensaje_ia_historial_idx');
        });
        Schema::table('mensajes_ia', function (Blueprint $table) {
            $table->foreign('respuesta_a_id')->references('id')->on('mensajes_ia')->restrictOnDelete();
        });

        Schema::create('evidencias_mensaje_ia', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mensaje_ia_id')->constrained('mensajes_ia')->restrictOnDelete();
            $table->string('tipo_evidencia_mensaje_ia', 24);
            $table->foreignUuid('definicion_campo_id')->nullable()->constrained('definiciones_campo')->restrictOnDelete();
            $table->foreignUuid('fuente_academica_id')->nullable()->constrained('fuentes_academicas')->restrictOnDelete();
            $table->string('etiqueta_evidencia_mensaje_ia', 180);
            $table->text('extracto_evidencia_mensaje_ia');
            $table->char('huella_evidencia_mensaje_ia', 64);
            $table->timestampTz('created_at');

            $table->unique(
                ['mensaje_ia_id', 'huella_evidencia_mensaje_ia'],
                'evidencia_mensaje_ia_huella_unica',
            );
        });

        Schema::create('citas_mensaje_ia', function (Blueprint $table) {
            $table->foreignUuid('mensaje_ia_id')->constrained('mensajes_ia')->restrictOnDelete();
            $table->foreignUuid('evidencia_mensaje_ia_id')->constrained('evidencias_mensaje_ia')->restrictOnDelete();
            $table->unsignedSmallInteger('ordinal_cita_mensaje_ia');

            $table->primary(['mensaje_ia_id', 'evidencia_mensaje_ia_id']);
            $table->unique(['mensaje_ia_id', 'ordinal_cita_mensaje_ia'], 'cita_mensaje_ia_ordinal_unico');
        });

        DB::statement("ALTER TABLE mensajes_ia ADD CONSTRAINT mensajes_ia_rol_check CHECK (rol_mensaje_ia IN ('usuario', 'asistente'))");
        DB::statement("ALTER TABLE mensajes_ia ADD CONSTRAINT mensajes_ia_estado_check CHECK (estado_mensaje_ia IN ('completado', 'pendiente', 'en_ejecucion', 'fallido'))");
        DB::statement("ALTER TABLE evidencias_mensaje_ia ADD CONSTRAINT evidencias_mensaje_ia_tipo_check CHECK (tipo_evidencia_mensaje_ia IN ('seccion_silabo', 'fuente_academica'))");
        DB::statement('ALTER TABLE evidencias_mensaje_ia ADD CONSTRAINT evidencias_mensaje_ia_referencia_check CHECK ((tipo_evidencia_mensaje_ia = \'seccion_silabo\' AND definicion_campo_id IS NOT NULL AND fuente_academica_id IS NULL) OR (tipo_evidencia_mensaje_ia = \'fuente_academica\' AND fuente_academica_id IS NOT NULL AND definicion_campo_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('citas_mensaje_ia');
        Schema::dropIfExists('evidencias_mensaje_ia');
        Schema::dropIfExists('mensajes_ia');
        Schema::dropIfExists('conversaciones_ia');
    }
};
