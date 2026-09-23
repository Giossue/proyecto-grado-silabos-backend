<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I-116: Administración decide en la plantilla qué campos usan IA. Coordinación ya
 * no sustituye ese valor por carrera; la IA solo sugiere y el docente decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('preferencias_ia_carrera');

        Schema::table('definiciones_campo', function (Blueprint $table): void {
            $table->dropColumn('ia_coordinacion_configurable');
        });
    }

    public function down(): void
    {
        Schema::table('definiciones_campo', function (Blueprint $table): void {
            $table->boolean('ia_coordinacion_configurable')->default(true);
        });

        Schema::create('preferencias_ia_carrera', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('carrera_id')->constrained('carreras')->restrictOnDelete();
            $table->foreignUuid('definicion_campo_id')->constrained('definiciones_campo')->restrictOnDelete();
            $table->boolean('ia_habilitada');
            $table->unsignedBigInteger('revision_plantilla_origen');
            $table->foreignUuid('configurado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('configurado_en');
            $table->unique(['carrera_id', 'definicion_campo_id'], 'preferencia_ia_carrera_campo_unica');
        });
    }
};
