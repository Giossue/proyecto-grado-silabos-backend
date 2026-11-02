<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes del sistema que Administración cambia desde la pantalla (notificaciones, IA, biblioteca).
 * Mientras un ajuste no esté aquí, vale la variable de entorno. La clave de la IA se guarda cifrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_sistema', function (Blueprint $table): void {
            $table->string('clave_ajuste', 60)->primary();
            $table->jsonb('valor_ajuste');
            $table->uuid('modificado_por')->nullable();
            $table->timestampTz('modificado_en', 0)->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->foreign('modificado_por')->references('usuario_id')->on('usuarios')->nullOnDelete();
            $table->index('modificado_por');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_sistema');
    }
};
