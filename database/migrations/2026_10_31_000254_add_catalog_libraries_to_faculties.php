<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada facultad indica en qué bibliotecas de Koha buscan sus docentes los libros físicos (por
 * ejemplo la de su campus). Se guardan los códigos de Koha; vacío significa «todas».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE facultades ADD COLUMN bibliotecas_koha_facultad jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("ALTER TABLE facultades ADD CONSTRAINT facultades_bibliotecas_koha_lista_check CHECK (jsonb_typeof(bibliotecas_koha_facultad) = 'array')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE facultades DROP CONSTRAINT IF EXISTS facultades_bibliotecas_koha_lista_check');
        DB::statement('ALTER TABLE facultades DROP COLUMN IF EXISTS bibliotecas_koha_facultad');
    }
};
