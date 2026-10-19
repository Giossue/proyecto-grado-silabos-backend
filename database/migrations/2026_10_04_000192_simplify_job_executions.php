<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('trabajos_en_cola')->whereIn('tipo_trabajo', ['curriculo.extraer', 'fuente_academica.openparser'])->delete();
        DB::statement('ALTER TABLE trabajos_en_cola DROP COLUMN progreso, DROP COLUMN intentos_maximos, DROP COLUMN codigo_error');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-184.');
    }
};
