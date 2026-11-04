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
            $table->timestampTz('finalizada_en', 6)->nullable();
        });
        Schema::table('convocatorias_carreras', function (Blueprint $table): void {
            $table->string('estado_previo_cierre_convocatoria_carrera', 24)->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE convocatorias_universidad AS proceso
            SET finalizada_en = cierre.ocurrido_en,
                pausado_en = CASE WHEN cierre.estado_previo = 'abierto' THEN NULL ELSE proceso.pausado_en END
            FROM (
                SELECT DISTINCT ON (recurso_id) recurso_id, ocurrido_en, detalles->>'previous_state' AS estado_previo
                FROM eventos_auditoria
                WHERE accion = 'proceso_silabos.cerrado'
                  AND tipo_recurso = 'proceso_silabos'
                  AND resultado = 'exito'
                ORDER BY recurso_id, ocurrido_en DESC
            ) AS cierre
            WHERE cierre.recurso_id = proceso.convocatoria_universidad_id
              AND proceso.estado_convocatoria_universidad = 'cerrado'
            SQL);
        DB::statement(<<<'SQL'
            UPDATE convocatorias_carreras AS convocatoria
            SET estado_previo_cierre_convocatoria_carrera = CASE
                WHEN (
                    SELECT evento.accion
                    FROM eventos_auditoria AS evento
                    WHERE evento.tipo_recurso = 'convocatoria'
                      AND evento.recurso_id = convocatoria.convocatoria_carrera_id
                      AND evento.accion IN ('convocatoria.abierta', 'convocatoria.pausada')
                      AND evento.resultado = 'exito'
                    ORDER BY evento.ocurrido_en DESC
                    LIMIT 1
                ) = 'convocatoria.pausada' THEN 'pausada'
                ELSE 'abierta'
            END
            WHERE convocatoria.estado_convocatoria_carrera = 'cerrada'
            SQL);

        DB::statement("ALTER TABLE convocatorias_universidad ADD CONSTRAINT convocatorias_universidad_finalizada_en_check CHECK (finalizada_en IS NULL OR estado_convocatoria_universidad = 'cerrado')");
        DB::statement("ALTER TABLE convocatorias_carreras ADD CONSTRAINT convocatorias_carreras_estado_previo_cierre_check CHECK (estado_previo_cierre_convocatoria_carrera IS NULL OR (estado_convocatoria_carrera = 'cerrada' AND estado_previo_cierre_convocatoria_carrera IN ('abierta', 'pausada')))");
    }

    public function down(): void
    {
        Schema::table('convocatorias_carreras', function (Blueprint $table): void {
            $table->dropColumn('estado_previo_cierre_convocatoria_carrera');
        });
        Schema::table('convocatorias_universidad', function (Blueprint $table): void {
            $table->dropColumn('finalizada_en');
        });
    }
};
