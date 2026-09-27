<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bases_resultados_asignatura', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('asignatura_id')->unique()->constrained('asignaturas')->restrictOnDelete();
            $table->string('nombre_fuente', 240);
            $table->string('localizador_fuente', 500);
            $table->foreignUuid('confirmado_por')->index()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('confirmado_en');
        });
        Schema::create('resultados_aprendizaje_asignatura', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('base_resultados_id')->constrained('bases_resultados_asignatura')->cascadeOnDelete();
            $table->text('texto_resultado');
            $table->unsignedInteger('posicion_resultado');
            $table->unique(['base_resultados_id', 'posicion_resultado'], 'resultados_asignatura_posicion_unica');
        });
        DB::statement("ALTER TABLE bases_resultados_asignatura ADD CONSTRAINT bases_resultados_fuente_completa CHECK (btrim(nombre_fuente) <> '' AND btrim(localizador_fuente) <> '')");
        DB::statement("ALTER TABLE resultados_aprendizaje_asignatura ADD CONSTRAINT resultados_asignatura_texto_presente CHECK (btrim(texto_resultado) <> '')");
        DB::statement('ALTER TABLE resultados_aprendizaje_asignatura ADD CONSTRAINT resultados_asignatura_posicion_positiva CHECK (posicion_resultado > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_aprendizaje_asignatura');
        Schema::dropIfExists('bases_resultados_asignatura');
    }
};
