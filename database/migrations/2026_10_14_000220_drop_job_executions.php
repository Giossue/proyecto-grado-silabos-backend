<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE exportaciones_silabo DROP CONSTRAINT exportaciones_silabo_trabajo_id_foreign');
        DB::statement('ALTER TABLE exportaciones_silabo DROP COLUMN trabajo_id');
        DB::statement('DROP TABLE trabajos_en_cola');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-221.');
    }
};
