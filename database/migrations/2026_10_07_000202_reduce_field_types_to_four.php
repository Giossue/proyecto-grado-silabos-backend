<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I-195 etapa 2: `definiciones_campo.tipo_definicion_campo` queda en cuatro tipos
 * (texto, ficha, tabla, automatico). La configuración de cada tipo vive en
 * `opciones_definicion_campo` (JSON).
 *
 * Filas antiguas: se convierten de forma explícita, sin borrar nada. Todo tipo antiguo pasa a
 * `texto`, salvo `texto_libre` heredado de la ficha (`ficha`) y `referencia_maestra`
 * (`automatico`). El tipo antiguo se conserva en `clase_anterior_definicion_campo`, capa de
 * compatibilidad temporal que se elimina en la etapa 8 del plan I-195.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('definiciones_campo', function ($table): void {
            $table->string('clase_anterior_definicion_campo', 40)->nullable();
        });

        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_tipo_check');
        DB::statement('UPDATE definiciones_campo SET clase_anterior_definicion_campo = tipo_definicion_campo');
        DB::statement("UPDATE definiciones_campo SET tipo_definicion_campo = CASE
            WHEN clase_anterior_definicion_campo = 'texto_libre' AND heredado_definicion_campo AND origen_maestro_definicion_campo = 'ficha' THEN 'ficha'
            WHEN clase_anterior_definicion_campo = 'referencia_maestra' THEN 'automatico'
            ELSE 'texto' END");
        DB::statement("ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_tipo_check CHECK (tipo_definicion_campo IN ('texto', 'ficha', 'tabla', 'automatico'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_tipo_check');
        DB::statement("UPDATE definiciones_campo SET tipo_definicion_campo = COALESCE(clase_anterior_definicion_campo, CASE tipo_definicion_campo
            WHEN 'ficha' THEN 'texto_libre' WHEN 'automatico' THEN 'referencia_maestra' ELSE 'texto_largo' END)");
        DB::statement("ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_tipo_check CHECK (tipo_definicion_campo IN ('texto_corto', 'texto_largo', 'numero', 'fecha', 'seleccion_unica', 'seleccion_multiple', 'booleano', 'repetible', 'calculo', 'referencia_maestra', 'texto_libre'))");
        Schema::table('definiciones_campo', function ($table): void {
            $table->dropColumn('clase_anterior_definicion_campo');
        });
    }
};
