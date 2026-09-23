<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('carreras')->whereNull('codigo_carrera')->exists()) {
            throw new RuntimeException('No se pueden retirar los códigos de facultad y malla: cada carrera debe tener un código de carrera.');
        }

        DB::statement('ALTER TABLE carreras ALTER COLUMN codigo_carrera SET NOT NULL');

        Schema::table('facultades', function (Blueprint $table): void {
            $table->dropColumn('codigo_facultad');
        });
        Schema::table('carreras', function (Blueprint $table): void {
            $table->dropColumn('codigo_malla');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('I-107 elimina datos de código de facultad y malla; restaure desde un respaldo anterior a la migración.');
    }
};
