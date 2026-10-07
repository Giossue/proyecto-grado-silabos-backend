<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-203: la formación y experiencia no se escribe en cada sílabo: sale del perfil del docente
 * (Configuración → Perfil), que la escribe una sola vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE definiciones_campo SET heredado_definicion_campo = true, origen_dato_definicion_campo = 'perfil_docente', editable_docente_definicion_campo = false
            WHERE clave_definicion_campo = 'formacion_experiencia' AND tipo_definicion_campo = 'texto'");
    }

    public function down(): void
    {
        DB::statement("UPDATE definiciones_campo SET heredado_definicion_campo = false, origen_dato_definicion_campo = NULL, editable_docente_definicion_campo = true
            WHERE clave_definicion_campo = 'formacion_experiencia' AND tipo_definicion_campo = 'texto'");
    }
};
