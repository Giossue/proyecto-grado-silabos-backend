<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las secciones de identificación institucional ya creadas pasan a ser compactas (letra de 9 pt y
 * margen de 1.5 cm en esa hoja), igual que las nuevas, y pierden el nombre del campo (el subtítulo «1.1»).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            UPDATE bloques_plantilla
            SET configuracion = jsonb_set(configuracion, '{compact}', 'true'::jsonb)
            WHERE configuracion->>'content_type' = 'institutional';
            UPDATE bloques_plantilla SET titulo = ''
            WHERE configuracion->>'content_type' = 'institutional';
            UPDATE definiciones_campo SET etiqueta = ''
            WHERE clave = 'asignatura' AND origen_maestro = 'asignaturas';
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            UPDATE bloques_plantilla
            SET configuracion = configuracion - 'compact'
            WHERE configuracion->>'content_type' = 'institutional';
            SQL);
    }
};
