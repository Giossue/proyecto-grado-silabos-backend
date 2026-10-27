<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('definiciones_campo', fn (Blueprint $table) => $table->index('plantilla_id'));
        Schema::table('ayudas_pantalla', fn (Blueprint $table) => $table->index('actualizado_por'));
        DB::statement('DROP FUNCTION IF EXISTS proteger_datos_entrada_trabajo()');
    }

    public function down(): void
    {
        Schema::table('definiciones_campo', fn (Blueprint $table) => $table->dropIndex(['plantilla_id']));
        Schema::table('ayudas_pantalla', fn (Blueprint $table) => $table->dropIndex(['actualizado_por']));
    }
};
