<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-180: `archivos.carrera_id` no hace falta; el archivo se atribuye por `subido_por`.
 * No se revierte: la reversa exige restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        DB::statement('ALTER TABLE archivos DROP COLUMN carrera_id');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-180.');
    }
};
