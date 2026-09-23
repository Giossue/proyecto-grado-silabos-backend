<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('convocatorias_universidad')
            ->where('estado_convocatoria_universidad', 'preparacion')
            ->exists()) {
            throw new RuntimeException(
                'Existen convocatorias institucionales en preparación. Elimínelas o ábralas antes de aplicar esta migración.',
            );
        }

        DB::statement('ALTER TABLE convocatorias_universidad DROP CONSTRAINT IF EXISTS procesos_silabos_estado_check');
        DB::statement('ALTER TABLE convocatorias_universidad DROP CONSTRAINT IF EXISTS convocatorias_universidad_estado_check');
        DB::statement("ALTER TABLE convocatorias_universidad ALTER COLUMN estado_convocatoria_universidad SET DEFAULT 'abierto'");
        DB::statement(<<<'SQL'
            ALTER TABLE convocatorias_universidad
            ADD CONSTRAINT convocatorias_universidad_estado_check
            CHECK (estado_convocatoria_universidad IN ('abierto', 'pausado', 'cerrado'))
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La eliminación del estado de preparación no admite reversión automática.');
    }
};
