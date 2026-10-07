<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-205: el docente solo escribe la planificación (sección 6), su formación (desde su perfil) y la
 * discapacidad. La metodología (7.1 a 7.5) y la bibliografía salen de la ficha de la asignatura.
 */
return new class extends Migration
{
    private const KEYS = ['metodologia', 'tecnicas_ensenanza', 'recursos_medios', 'herramientas_tic', 'ambientes_aprendizaje'];

    public function up(): void
    {
        $keys = "'".implode("','", self::KEYS)."'";
        DB::statement("UPDATE definiciones_campo SET tipo_definicion_campo = 'ficha', heredado_definicion_campo = true, origen_dato_definicion_campo = 'ficha',
            editable_docente_definicion_campo = false, opciones_definicion_campo = '{\"adjustable\": false}'::jsonb
            WHERE clave_definicion_campo IN ({$keys}) AND tipo_definicion_campo IN ('texto', 'ficha')");
        DB::statement("UPDATE definiciones_campo SET opciones_definicion_campo = '{\"adjustable\": true, \"optional\": true}'::jsonb
            WHERE clave_definicion_campo IN ('bibliografia', 'bibliografia_complementaria')");
    }

    public function down(): void
    {
        DB::statement("UPDATE definiciones_campo SET tipo_definicion_campo = 'texto', heredado_definicion_campo = false, origen_dato_definicion_campo = NULL,
            editable_docente_definicion_campo = true, opciones_definicion_campo = NULL
            WHERE clave_definicion_campo IN ('metodologia', 'tecnicas_ensenanza', 'recursos_medios', 'herramientas_tic', 'ambientes_aprendizaje')");
        DB::statement("UPDATE definiciones_campo SET opciones_definicion_campo = '{\"adjustable\": true}'::jsonb
            WHERE clave_definicion_campo IN ('bibliografia', 'bibliografia_complementaria')");
    }
};
