<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('asignaciones_paralelo')
            ->select('paralelo_id')
            ->where('docente_paralelo_activo', true)
            ->groupBy('paralelo_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'No se puede restringir la responsabilidad activa de asignaciones_paralelo: existen paralelos con más de un docente activo.',
            );
        }

        DB::statement('DROP INDEX IF EXISTS asignaciones_paralelo_identidad_unica');
        DB::statement(
            'CREATE UNIQUE INDEX asignaciones_paralelo_paralelo_activo_unico ON asignaciones_paralelo (paralelo_id) WHERE docente_paralelo_activo',
        );
    }

    public function down(): void
    {
        $duplicates = DB::table('asignaciones_paralelo')
            ->select('asignacion_rol_id', 'paralelo_id')
            ->groupBy('asignacion_rol_id', 'paralelo_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'No se puede restaurar la identidad histórica global: existen filas repetidas de asignaciones_paralelo.',
            );
        }

        DB::statement('DROP INDEX IF EXISTS asignaciones_paralelo_paralelo_activo_unico');
        DB::statement(
            'CREATE UNIQUE INDEX asignaciones_paralelo_identidad_unica ON asignaciones_paralelo (asignacion_rol_id, paralelo_id)',
        );
    }
};
