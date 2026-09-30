<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE exportaciones_silabo DROP COLUMN IF EXISTS idioma');
        DB::statement('ALTER TABLE exportaciones_silabo DROP COLUMN IF EXISTS version_generador');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE exportaciones_silabo ADD COLUMN IF NOT EXISTS idioma varchar(20) NOT NULL DEFAULT 'es-EC'");
        DB::statement("ALTER TABLE exportaciones_silabo ADD COLUMN IF NOT EXISTS version_generador varchar(80) NOT NULL DEFAULT ''");
    }
};
