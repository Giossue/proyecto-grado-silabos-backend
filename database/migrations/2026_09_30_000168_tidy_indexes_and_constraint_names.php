<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINTS = [
        'artefactos_exportacion' => ['exportacion_revision_idempotencia_unica' => 'artefactos_exportacion_revision_idempotencia_unica'],
        'convocatorias_carreras' => ['convocatorias_estado_check' => 'convocatorias_carreras_estado_check'],
        'convocatorias_universidad' => ['procesos_silabos_fechas_check' => 'convocatorias_universidad_fechas_check'],
        'periodos_academicos' => [
            'periodos_fechas_check' => 'periodos_academicos_fechas_check',
            'periodos_semanas_lectivas_rango' => 'periodos_academicos_semanas_lectivas_rango',
            'periodos_codigo_periodo_academico_unico' => 'periodos_academicos_codigo_unico',
        ],
        'programaciones_asignatura' => ['programacion_asignatura_periodo_materia_unica' => 'programaciones_asignatura_periodo_materia_unica'],
    ];

    private const INDEXES = [
        'asignacion_rol_activa_unica' => 'asignaciones_rol_activa_unica',
        'campanias_carrera_id_estado_index' => 'convocatorias_carreras_carrera_id_estado_index',
        'convocatorias_carrera_proceso_unico' => 'convocatorias_carreras_carrera_proceso_unico',
        'procesos_silabos_en_curso_unico' => 'convocatorias_universidad_en_curso_unico',
        'procesos_silabos_periodo_unico' => 'convocatorias_universidad_periodo_unico',
        'auditoria_actor_fecha_idx' => 'eventos_auditoria_actor_fecha_idx',
        'auditoria_recurso_fecha_idx' => 'eventos_auditoria_recurso_fecha_idx',
    ];

    public function up(): void
    {
        foreach ([
            'importaciones_curriculares_carrera_id_index',
            'asignaturas_asignatura_activa_index',
            'carreras_carrera_activa_index',
            'facultades_facultad_activa_index',
            'programaciones_asignatura_activa_index',
            'fuentes_academicas_activa_index',
            'procesos_silabos_estado_idx',
            'valores_curriculares_carrera_id_index',
            'valores_curriculares_concepto_id_index',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
        DB::statement('CREATE INDEX valores_curriculares_concepto_id_carrera_id_index ON valores_curriculares (concepto_id, carrera_id)');
        DB::statement('ALTER TABLE valores_curriculares DROP CONSTRAINT IF EXISTS valores_curriculares_carrera_id_foreign');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW docentes_vigentes_silabo AS
            SELECT silabos.id AS silabo_id,
                asignaciones_rol.usuario_id,
                asignaciones_rol.id AS asignacion_rol_id,
                asignaciones_paralelo.id AS asignacion_paralelo_id,
                silabos.paralelo_id
            FROM silabos
            JOIN asignaciones_paralelo ON asignaciones_paralelo.paralelo_id = silabos.paralelo_id
                AND asignaciones_paralelo.docente_paralelo_activo
            JOIN asignaciones_rol ON asignaciones_rol.id = asignaciones_paralelo.asignacion_rol_id
                AND asignaciones_rol.asignacion_rol_activa
                AND asignaciones_rol.rol = 'docente'
        SQL);

        foreach (self::CONSTRAINTS as $table => $names) {
            foreach ($names as $old => $new) {
                $this->renameConstraint($table, $old, $new);
            }
        }
        foreach (self::INDEXES as $old => $new) {
            DB::statement("ALTER INDEX IF EXISTS {$old} RENAME TO {$new}");
        }

        $this->renameByConvention("contype = 'n'", "t.relname || '_' || a.attname || '_not_null'");
        $this->renameByConvention("contype = 'p'", "t.relname || '_pkey'");
        $this->renameByConvention("contype = 'f' AND cardinality(c.conkey) = 1", "t.relname || '_' || a.attname || '_foreign'");
    }

    public function down(): void
    {
        throw new LogicException('El orden de índices y nombres de I-168 no se revierte: restaure el respaldo previo.');
    }

    private function renameConstraint(string $table, string $old, string $new): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE t.relname = ? AND c.conname = ?',
            [$table, $old],
        );
        if ($exists !== null) {
            DB::statement("ALTER TABLE {$table} RENAME CONSTRAINT {$old} TO {$new}");
        }
    }

    private function renameByConvention(string $filter, string $expected): void
    {
        $rows = DB::select(<<<SQL
            SELECT t.relname AS tabla, c.conname AS actual, left({$expected}, 63) AS esperado
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = c.conkey[1]
            WHERE n.nspname = 'public' AND t.relkind = 'r' AND c.{$filter}
        SQL);
        foreach ($rows as $row) {
            $taken = DB::selectOne(
                'SELECT 1 FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE t.relname = ? AND c.conname = ?',
                [$row->tabla, $row->esperado],
            );
            if ($row->actual !== $row->esperado && $taken === null) {
                DB::statement(sprintf('ALTER TABLE %s RENAME CONSTRAINT "%s" TO "%s"', $row->tabla, $row->actual, $row->esperado));
            }
        }
    }
};
