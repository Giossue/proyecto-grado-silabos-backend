<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->allow(['texto_libre']);
    }

    public function down(): void
    {
        DB::table('definiciones_campo')->where('tipo_definicion_campo', 'texto_libre')->update(['tipo_definicion_campo' => 'texto_largo']);
        $this->allow([]);
    }

    /** @param list<string> $extra */
    private function allow(array $extra): void
    {
        $types = ['texto_corto', 'texto_largo', 'numero', 'fecha', 'seleccion_unica', 'seleccion_multiple', 'booleano', 'repetible', 'calculo', 'referencia_maestra', ...$extra];
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_tipo_check');
        DB::statement('ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_tipo_check CHECK (tipo_definicion_campo IN ('
            .implode(', ', array_map(fn (string $type): string => "'{$type}'", $types)).'))');
    }
};
