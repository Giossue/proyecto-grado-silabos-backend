<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-185: `firmas_documento.hash_archivo` repetía la huella del PDF firmado, que ya guarda `archivos`.
 * No se revierte: la reversa exige restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE firmas_documento DROP COLUMN hash_archivo');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-185.');
    }
};
