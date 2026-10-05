<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->text('formacion_experiencia_usuario')->nullable();
        });

        // La formación y experiencia la escribe el docente una vez en su perfil y el sílabo la hereda.
        DB::table('definiciones_campo')
            ->where('clave_definicion_campo', 'formacion_experiencia')
            ->update([
                'heredado_definicion_campo' => true,
                'origen_dato_definicion_campo' => 'perfil_docente',
                'editable_docente_definicion_campo' => false,
            ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-190.');
    }
};
