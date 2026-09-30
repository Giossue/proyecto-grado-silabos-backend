<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const VALUES = [
        'presencial' => 'Presencial',
        'semipresencial' => 'Semipresencial',
        'en_linea' => 'En línea',
        'a_distancia' => 'A distancia',
        'hibrida' => 'Híbrida',
    ];

    public function up(): void
    {
        foreach (self::VALUES as $old => $new) {
            DB::statement("ALTER TYPE modalidad_estudio RENAME VALUE '{$old}' TO '{$new}'");
        }
    }

    public function down(): void
    {
        foreach (self::VALUES as $old => $new) {
            DB::statement("ALTER TYPE modalidad_estudio RENAME VALUE '{$new}' TO '{$old}'");
        }
    }
};
