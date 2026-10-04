<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-177: se retiran las observaciones por campo. Cada revisor devuelve el sílabo con un
 * comentario general (en `revisiones_silabo.correccion_justificacion`); biblioteca elige
 * motivos predefinidos. No se revierte: la reversa exige restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE observaciones_revision');
        DB::statement('DROP FUNCTION IF EXISTS proteger_contenido_observacion()');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-177.');
    }
};
