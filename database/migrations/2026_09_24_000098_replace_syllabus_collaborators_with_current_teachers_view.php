<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS colaboradores_silabo');

        DB::statement(<<<'SQL'
            CREATE VIEW docentes_vigentes_silabo AS
            SELECT DISTINCT
                alcances_silabo.silabo_id,
                asignaciones_rol.usuario_id,
                asignaciones_rol.id AS asignacion_rol_id,
                asignaciones_paralelo.id AS asignacion_paralelo_id,
                alcances_silabo.paralelo_id
            FROM alcances_silabo
            JOIN asignaciones_paralelo
                ON asignaciones_paralelo.paralelo_id = alcances_silabo.paralelo_id
                AND asignaciones_paralelo.docente_paralelo_activo
            JOIN asignaciones_rol
                ON asignaciones_rol.id = asignaciones_paralelo.asignacion_rol_id
                AND asignaciones_rol.asignacion_rol_activa
                AND asignaciones_rol.rol = 'docente'
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La eliminación de colaboradores por sílabo no admite reversión automática.');
    }
};
