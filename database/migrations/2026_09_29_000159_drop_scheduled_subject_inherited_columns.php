<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I-164: campus y modalidad de una programación se derivan de la materia, su carrera y su
 * facultad; la copia guardada solo podía quedar desactualizada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programaciones_asignatura', function (Blueprint $table): void {
            $table->dropColumn(['campus_programacion', 'modalidad_programacion_asignatura']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Las columnas derivadas de la programación (I-164) no se restauran: restaure el respaldo previo.');
    }
};
