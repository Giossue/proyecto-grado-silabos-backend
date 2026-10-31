<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensajes_ia_plantilla', function (Blueprint $table): void {
            $table->uuid('mensaje_ia_plantilla_id')->primary();
            $table->uuid('plantilla_id');
            $table->uuid('usuario_id');
            $table->jsonb('contenido_mensaje');
            $table->timestampTz('enviado_en', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('ocultado_en', 6)->nullable();

            $table->foreign('plantilla_id')->references('plantilla_id')->on('plantilla_silabo')->cascadeOnDelete();
            $table->foreign('usuario_id')->references('usuario_id')->on('usuarios')->restrictOnDelete();
            $table->index(['plantilla_id', 'usuario_id', 'enviado_en']);
            $table->index('usuario_id');
        });
        DB::statement("ALTER TABLE mensajes_ia_plantilla ADD CONSTRAINT mensajes_ia_plantilla_contenido_check CHECK (jsonb_typeof(contenido_mensaje) = 'object' AND contenido_mensaje->>'rol' IN ('usuario', 'asistente') AND contenido_mensaje->>'estado' IN ('pendiente', 'en_ejecucion', 'completado', 'fallido'))");
        DB::statement("CREATE UNIQUE INDEX mensajes_ia_plantilla_idempotencia_unica ON mensajes_ia_plantilla (plantilla_id, usuario_id, (contenido_mensaje->'entrada'->>'clave_idempotencia')) WHERE contenido_mensaje->>'rol' = 'usuario'");

        Schema::create('propuestas_ia_plantilla', function (Blueprint $table): void {
            $table->uuid('propuesta_ia_plantilla_id')->primary();
            $table->uuid('plantilla_id');
            $table->uuid('mensaje_ia_plantilla_id')->unique();
            $table->uuid('solicitada_por');
            $table->jsonb('estructura_propuesta');
            $table->jsonb('cambios_propuesta');
            $table->char('huella_plantilla_base', 64);
            $table->string('estado_propuesta', 20)->default('pendiente');
            $table->uuid('decidida_por')->nullable();
            $table->timestampTz('decidida_en', 6)->nullable();
            $table->timestampTz('creada_en', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('plantilla_id')->references('plantilla_id')->on('plantilla_silabo')->cascadeOnDelete();
            $table->foreign('mensaje_ia_plantilla_id')->references('mensaje_ia_plantilla_id')->on('mensajes_ia_plantilla')->cascadeOnDelete();
            $table->foreign('solicitada_por')->references('usuario_id')->on('usuarios')->restrictOnDelete();
            $table->foreign('decidida_por')->references('usuario_id')->on('usuarios')->restrictOnDelete();
            $table->index(['plantilla_id', 'estado_propuesta']);
            $table->index('solicitada_por');
            $table->index('decidida_por');
        });
        DB::statement("ALTER TABLE propuestas_ia_plantilla ADD CONSTRAINT propuestas_ia_plantilla_estado_check CHECK (estado_propuesta IN ('pendiente', 'aplicada', 'descartada', 'vencida', 'reemplazada'))");
        DB::statement("ALTER TABLE propuestas_ia_plantilla ADD CONSTRAINT propuestas_ia_plantilla_decision_check CHECK (estado_propuesta NOT IN ('aplicada', 'descartada') OR (decidida_por IS NOT NULL AND decidida_en IS NOT NULL))");
        DB::statement("ALTER TABLE propuestas_ia_plantilla ADD CONSTRAINT propuestas_ia_plantilla_huella_check CHECK (huella_plantilla_base ~ '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('propuestas_ia_plantilla');
        Schema::dropIfExists('mensajes_ia_plantilla');
    }
};
