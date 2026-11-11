<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rename('coordinacion.aprobado', 'silabo.enviado');
    }

    public function down(): void
    {
        $this->rename('silabo.enviado', 'coordinacion.aprobado');
    }

    private function rename(string $from, string $to): void
    {
        DB::update(
            'UPDATE usuarios SET preferencias_correo_usuario = (preferencias_correo_usuario - ?::text) || jsonb_build_object(?::text, preferencias_correo_usuario -> ?::text) WHERE jsonb_exists(preferencias_correo_usuario, ?::text)',
            [$from, $to, $from, $from],
        );
    }
};
