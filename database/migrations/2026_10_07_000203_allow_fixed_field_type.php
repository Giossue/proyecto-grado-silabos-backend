<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_tipo_check');
        DB::statement("ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_tipo_check CHECK (tipo_definicion_campo IN ('texto', 'ficha', 'tabla', 'automatico', 'fijo'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE definiciones_campo SET tipo_definicion_campo = 'texto' WHERE tipo_definicion_campo = 'fijo'");
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_tipo_check');
        DB::statement("ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_tipo_check CHECK (tipo_definicion_campo IN ('texto', 'ficha', 'tabla', 'automatico'))");
    }
};
