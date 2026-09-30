<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUMNS = [
        'convocatorias_carreras' => ['proceso_id' => 'convocatoria_universidad_id'],
        'silabos' => ['convocatoria_id' => 'convocatoria_carrera_id'],
        'asignaciones_paralelo' => ['docente_paralelo_activo' => 'asignacion_paralelo_activa'],
        'ejecuciones_validacion' => ['errores_bloqueantes' => 'cantidad_errores_validacion'],
        'definiciones_campo' => [
            'heredado' => 'heredado_definicion_campo',
            'origen_maestro' => 'origen_maestro_definicion_campo',
            'editable_docente' => 'editable_docente_definicion_campo',
            'mostrar_si' => 'mostrar_si_definicion_campo',
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $old => $new) {
                DB::statement("ALTER TABLE {$table} RENAME COLUMN {$old} TO {$new}");
            }
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION impedir_cambio_periodo_proceso_con_convocatorias() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.periodo_academico_id IS DISTINCT FROM OLD.periodo_academico_id
                   AND EXISTS (SELECT 1 FROM convocatorias_carreras WHERE convocatoria_universidad_id = OLD.id) THEN
                    RAISE EXCEPTION 'No se puede cambiar el período de una convocatoria universitaria con convocatorias de carrera.';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

        $rows = DB::select(<<<'SQL'
            SELECT t.relname AS tabla, c.conname AS actual,
                left(t.relname || '_' || a.attname || CASE c.contype WHEN 'n' THEN '_not_null' ELSE '_foreign' END, 63) AS esperado
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = c.conkey[1]
            WHERE n.nspname = 'public' AND (c.contype = 'n' OR (c.contype = 'f' AND cardinality(c.conkey) = 1))
        SQL);
        foreach ($rows as $row) {
            if ($row->actual !== $row->esperado) {
                DB::statement(sprintf('ALTER TABLE %s RENAME CONSTRAINT "%s" TO "%s"', $row->tabla, $row->actual, $row->esperado));
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('El renombrado de columnas de I-168 no se revierte: restaure el respaldo previo.');
    }
};
