<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convocatorias_universidad', function (Blueprint $table): void {
            $table->integer('cantidad_prorrogas')->default(0);
        });
        DB::statement('ALTER TABLE convocatorias_universidad ADD CONSTRAINT convocatorias_universidad_cantidad_prorrogas_check CHECK (cantidad_prorrogas >= 0)');
        DB::statement(<<<'SQL'
            UPDATE convocatorias_universidad AS proceso
            SET cantidad_prorrogas = prorrogas.total
            FROM (
                SELECT recurso_id, count(*) AS total
                FROM eventos_auditoria
                WHERE accion = 'proceso_silabos.plazo_extendido'
                  AND tipo_recurso = 'proceso_silabos'
                  AND resultado = 'exito'
                GROUP BY recurso_id
            ) AS prorrogas
            WHERE prorrogas.recurso_id = proceso.convocatoria_universidad_id
            SQL);
    }

    public function down(): void
    {
        Schema::table('convocatorias_universidad', function (Blueprint $table): void {
            $table->dropColumn('cantidad_prorrogas');
        });
    }
};
