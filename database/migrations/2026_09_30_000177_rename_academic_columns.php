<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['asignaturas_ofertadas', 'paralelos', 'asignaturas', 'carreras', 'convocatorias_universidad', 'importaciones_curriculares'];

    private const COLUMNS = [
        'programaciones_asignatura' => ['programacion_asignatura_activa' => 'asignatura_ofertada_activa'],
        'paralelos' => ['programacion_asignatura_id' => 'asignatura_ofertada_id'],
        'asignaturas' => [
            'horas_ac' => 'horas_docencia',
            'horas_pae' => 'horas_practicas',
            'horas_aa' => 'horas_autonomas',
            'unidad_organizativa_curricular_asignatura' => 'unidad_curricular_asignatura',
        ],
        'carreras' => ['cantidad_ciclos_malla' => 'numero_ciclos'],
        'convocatorias_universidad' => ['inicia_en' => 'fecha_inicio', 'entrega_en' => 'fecha_entrega'],
        'importaciones_curriculares' => ['clave_idempotencia_importacion' => 'clave_solicitud'],
    ];

    private const CONSTRAINTS = [
        'asignaturas' => ['asignaturas_unidad_organizativa_curricular_check' => 'asignaturas_unidad_curricular_check'],
        'carreras' => ['carreras_cantidad_ciclos_malla_check' => 'carreras_numero_ciclos_check'],
        'importaciones_curriculares' => ['importaciones_curriculares_idempotencia_unica' => 'importaciones_curriculares_clave_solicitud_unica'],
        'paralelos' => ['paralelos_programacion_asignatura_id_codigo_paralelo_unique' => 'paralelos_asignatura_ofertada_id_codigo_paralelo_unique'],
        'asignaturas_ofertadas' => ['programaciones_asignatura_periodo_materia_unica' => 'asignaturas_ofertadas_periodo_materia_unica'],
    ];

    private const INDEXES = [
        'programaciones_asignatura_asignatura_id_index' => 'asignaturas_ofertadas_asignatura_id_index',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $old => $new) {
                    $blueprint->renameColumn($old, $new);
                }
            });
        }
        Schema::rename('programaciones_asignatura', 'asignaturas_ofertadas');

        foreach (self::CONSTRAINTS as $table => $names) {
            foreach ($names as $old => $new) {
                if ($this->constraintExists($table, $old)) {
                    DB::statement("ALTER TABLE {$table} RENAME CONSTRAINT {$old} TO {$new}");
                }
            }
        }
        foreach (self::INDEXES as $old => $new) {
            DB::statement("ALTER INDEX IF EXISTS {$old} RENAME TO {$new}");
        }

        DB::statement("COMMENT ON COLUMN asignaturas.horas_docencia IS 'Horas de docencia (SIANET horas_ac)'");
        DB::statement("COMMENT ON COLUMN asignaturas.horas_practicas IS 'Horas de prácticas (SIANET horas_pae)'");
        DB::statement("COMMENT ON COLUMN asignaturas.horas_autonomas IS 'Horas autónomas (SIANET horas_aa)'");
        DB::statement("COMMENT ON COLUMN asignaturas.total_horas_asignatura IS 'Generada: horas_docencia + horas_practicas + horas_autonomas (I-132)'");

        $this->renameByConvention("contype = 'n'", "t.relname || '_' || a.attname || '_not_null'");
        $this->renameByConvention("contype = 'p'", "t.relname || '_pkey'");
        $this->renameByConvention("contype = 'f' AND cardinality(c.conkey) = 1", "t.relname || '_' || a.attname || '_foreign'");
    }

    public function down(): void
    {
        throw new LogicException('Los nombres académicos de I-177 no se revierten: restaure el respaldo previo.');
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE t.relname = ? AND c.conname = ?',
            [$table, $name],
        ) !== null;
    }

    private function renameByConvention(string $filter, string $expected): void
    {
        $tables = "'".implode("','", self::TABLES)."'";
        $rows = DB::select(<<<SQL
            SELECT t.relname AS tabla, c.conname AS actual, left({$expected}, 63) AS esperado
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = c.conkey[1]
            WHERE n.nspname = 'public' AND t.relkind = 'r' AND t.relname IN ({$tables}) AND c.{$filter}
        SQL);
        foreach ($rows as $row) {
            if ($row->actual !== $row->esperado && ! $this->constraintExists($row->tabla, $row->esperado)) {
                DB::statement(sprintf('ALTER TABLE %s RENAME CONSTRAINT "%s" TO "%s"', $row->tabla, $row->actual, $row->esperado));
            }
        }
    }
};
