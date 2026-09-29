<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asignaciones_docente', function (Blueprint $table): void {
            $table->string('sustento_tipo', 40)->nullable();
            $table->string('sustento_numero', 80)->nullable();
            $table->date('sustento_fecha')->nullable();
        });

        Schema::table('asignaciones_coordinador', function (Blueprint $table): void {
            $table->string('calidad', 16)->default('titular');
            $table->string('sustento_tipo', 40)->nullable();
            $table->string('sustento_numero', 80)->nullable();
            $table->date('sustento_fecha')->nullable();
        });

        DB::statement("ALTER TABLE asignaciones_coordinador ADD CONSTRAINT asignaciones_coordinador_calidad_check CHECK (calidad IN ('titular', 'encargado'))");

        DB::statement('ALTER TABLE asignaciones_coordinador ADD CONSTRAINT asignaciones_coordinador_encargo_check CHECK (calidad <> \'encargado\' OR vigente_hasta IS NOT NULL)');

        DB::statement('ALTER TABLE fechas_limite_convocatoria DROP CONSTRAINT fechas_limite_etapa_check');
        DB::statement("ALTER TABLE fechas_limite_convocatoria ADD CONSTRAINT fechas_limite_etapa_check CHECK (etapa IN ('start', 'draft', 'review', 'correction'))");
    }

    public function down(): void
    {
        DB::statement('DELETE FROM fechas_limite_convocatoria WHERE etapa = \'start\'');
        DB::statement('ALTER TABLE fechas_limite_convocatoria DROP CONSTRAINT fechas_limite_etapa_check');
        DB::statement("ALTER TABLE fechas_limite_convocatoria ADD CONSTRAINT fechas_limite_etapa_check CHECK (etapa IN ('draft', 'review', 'correction'))");

        DB::statement('ALTER TABLE asignaciones_coordinador DROP CONSTRAINT IF EXISTS asignaciones_coordinador_encargo_check');
        DB::statement('ALTER TABLE asignaciones_coordinador DROP CONSTRAINT IF EXISTS asignaciones_coordinador_calidad_check');

        Schema::table('asignaciones_coordinador', function (Blueprint $table): void {
            $table->dropColumn(['calidad', 'sustento_tipo', 'sustento_numero', 'sustento_fecha']);
        });

        Schema::table('asignaciones_docente', function (Blueprint $table): void {
            $table->dropColumn(['sustento_tipo', 'sustento_numero', 'sustento_fecha']);
        });
    }
};
