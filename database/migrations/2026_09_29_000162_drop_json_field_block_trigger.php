<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS validar_bloque_definicion_campo ON definiciones_campo;
            DROP FUNCTION IF EXISTS validar_bloque_definicion_campo();
            SQL);
    }

    public function down(): void {}
};
