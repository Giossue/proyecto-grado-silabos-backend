<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('asignaciones_paralelo')
            ->select('asignacion_rol_id', 'paralelo_id')
            ->where('docente_paralelo_activo', true)
            ->groupBy('asignacion_rol_id', 'paralelo_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'No se puede restringir la identidad activa de asignaciones_paralelo: existen asignaciones activas duplicadas.',
            );
        }

        DB::statement('DROP INDEX IF EXISTS asignaciones_paralelo_identidad_unica');
        DB::statement(
            'CREATE UNIQUE INDEX asignaciones_paralelo_identidad_activa_unica ON asignaciones_paralelo (asignacion_rol_id, paralelo_id) WHERE docente_paralelo_activo',
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

        DB::statement('DROP INDEX IF EXISTS asignaciones_paralelo_identidad_activa_unica');
        DB::statement(
            'CREATE UNIQUE INDEX asignaciones_paralelo_identidad_unica ON asignaciones_paralelo (asignacion_rol_id, paralelo_id)',
        );
    }
};
