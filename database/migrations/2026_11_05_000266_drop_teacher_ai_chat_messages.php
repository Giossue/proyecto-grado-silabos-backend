<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS mensajes_ia');
        DB::statement('DROP FUNCTION IF EXISTS proteger_mensaje_ia()');
        DB::statement('DROP FUNCTION IF EXISTS proteger_historial_ia()');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo al retiro del chat del docente.');
    }
};
