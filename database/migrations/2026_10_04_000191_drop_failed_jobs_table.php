<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-183: `trabajos_fallidos` sobra; los fallos quedan en `trabajos_en_cola`.
 * No se revierte: la reversa exige restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS trabajos_fallidos');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-183.');
    }
};
