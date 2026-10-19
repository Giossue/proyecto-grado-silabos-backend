<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE definiciones_campo SET opciones_definicion_campo = '{\"inside\": \"identification_card\", \"optional\": true}'::jsonb
            WHERE clave_definicion_campo IN ('discapacidad_tipo', 'discapacidad_adaptacion') AND tipo_definicion_campo = 'texto'");
    }

    public function down(): void
    {
        DB::statement("UPDATE definiciones_campo SET opciones_definicion_campo = '{\"inside\": \"identification_card\"}'::jsonb
            WHERE clave_definicion_campo IN ('discapacidad_tipo', 'discapacidad_adaptacion') AND tipo_definicion_campo = 'texto'");
    }
};
