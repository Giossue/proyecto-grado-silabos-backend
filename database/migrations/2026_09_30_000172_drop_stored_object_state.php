<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE objetos_almacenados DROP COLUMN IF EXISTS estado_objeto_almacenado');
        DB::statement('CREATE INDEX IF NOT EXISTS objetos_almacenados_clasificacion_almacenado_en_index ON objetos_almacenados (clasificacion, almacenado_en)');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE objetos_almacenados ADD COLUMN IF NOT EXISTS estado_objeto_almacenado varchar(24) NOT NULL DEFAULT 'activo'");
    }
};
