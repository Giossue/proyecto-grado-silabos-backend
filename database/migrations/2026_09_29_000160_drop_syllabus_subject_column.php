<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I-164: la materia de un sílabo se deduce de su paralelo (paralelo → programación →
 * materia); la copia en `silabos.asignatura_id` podía contradecirla.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS silabos_asignatura_id_estado_index');
        Schema::table('silabos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('asignatura_id');
        });
    }

    public function down(): void
    {
        throw new LogicException('La columna asignatura_id de silabos (I-164) no se restaura: restaure el respaldo previo.');
    }
};
