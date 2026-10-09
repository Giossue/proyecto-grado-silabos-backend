<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasPeriodColumn()) {
            return;
        }

        $duplicates = DB::select(<<<'SQL'
            SELECT paralelo_id, keep_id FROM (
                SELECT p.paralelo_id,
                    first_value(p.paralelo_id) OVER (
                        PARTITION BY p.asignatura_id, upper(p.codigo_paralelo)
                        ORDER BY pa.fecha_inicio_periodo DESC, p.paralelo_id
                    ) AS keep_id
                FROM paralelos p
                JOIN periodos_academicos pa ON pa.periodo_academico_id = p.periodo_academico_id
            ) ranked
            WHERE paralelo_id <> keep_id
        SQL);

        foreach ($duplicates as $duplicate) {
            DB::update('UPDATE silabos SET paralelo_id = ? WHERE paralelo_id = ?', [$duplicate->keep_id, $duplicate->paralelo_id]);
            DB::delete(
                'DELETE FROM asignaciones_paralelo WHERE paralelo_id = ? AND EXISTS (SELECT 1 FROM asignaciones_paralelo WHERE paralelo_id = ?)',
                [$duplicate->paralelo_id, $duplicate->keep_id],
            );
            DB::update('UPDATE asignaciones_paralelo SET paralelo_id = ? WHERE paralelo_id = ?', [$duplicate->keep_id, $duplicate->paralelo_id]);
            DB::delete('DELETE FROM paralelos WHERE paralelo_id = ?', [$duplicate->paralelo_id]);
        }

        DB::statement('ALTER TABLE paralelos DROP COLUMN periodo_academico_id');
        DB::statement('CREATE UNIQUE INDEX paralelos_asignatura_codigo_unique ON paralelos (asignatura_id, upper(codigo_paralelo))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS paralelos_asignatura_codigo_unique');
        DB::statement('ALTER TABLE paralelos ADD COLUMN IF NOT EXISTS periodo_academico_id uuid NULL');
        DB::statement(<<<'SQL'
            UPDATE paralelos p SET periodo_academico_id = (
                SELECT pa.periodo_academico_id FROM periodos_academicos pa ORDER BY pa.fecha_inicio_periodo DESC LIMIT 1
            ) WHERE p.periodo_academico_id IS NULL
        SQL);
        DB::statement('ALTER TABLE paralelos ALTER COLUMN periodo_academico_id SET NOT NULL');
        DB::statement('ALTER TABLE paralelos ADD CONSTRAINT paralelos_periodo_academico_id_foreign FOREIGN KEY (periodo_academico_id) REFERENCES periodos_academicos (periodo_academico_id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX paralelos_periodo_academico_id_index ON paralelos (periodo_academico_id)');
        DB::statement('ALTER TABLE paralelos ADD CONSTRAINT paralelos_periodo_asignatura_codigo_unique UNIQUE (periodo_academico_id, asignatura_id, codigo_paralelo)');
    }

    private function hasPeriodColumn(): bool
    {
        return DB::selectOne(
            "SELECT 1 AS found FROM information_schema.columns WHERE table_name = 'paralelos' AND column_name = 'periodo_academico_id'",
        ) !== null;
    }
};
