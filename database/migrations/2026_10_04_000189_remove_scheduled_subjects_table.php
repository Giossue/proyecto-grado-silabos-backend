<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-181: `asignaturas_ofertadas` sobra. A nivel institucional siempre se ofrecen todas las
 * materias de la malla, así que el paralelo apunta directo al período y a la materia.
 * No se revierte: la reversa exige restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE paralelos ADD COLUMN periodo_academico_id uuid NULL, ADD COLUMN asignatura_id uuid NULL');
        DB::statement(<<<'SQL'
            UPDATE paralelos p
            SET periodo_academico_id = o.periodo_academico_id, asignatura_id = o.asignatura_id
            FROM asignaturas_ofertadas o
            WHERE o.asignatura_ofertada_id = p.asignatura_ofertada_id
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE paralelos
            ALTER COLUMN periodo_academico_id SET NOT NULL,
            ALTER COLUMN asignatura_id SET NOT NULL,
            ADD CONSTRAINT paralelos_periodo_academico_id_foreign
                FOREIGN KEY (periodo_academico_id) REFERENCES periodos_academicos (periodo_academico_id) ON DELETE RESTRICT,
            ADD CONSTRAINT paralelos_asignatura_id_foreign
                FOREIGN KEY (asignatura_id) REFERENCES asignaturas (asignatura_id) ON DELETE RESTRICT
        SQL);
        DB::statement('CREATE INDEX paralelos_periodo_academico_id_index ON paralelos (periodo_academico_id)');
        DB::statement('CREATE INDEX paralelos_asignatura_id_index ON paralelos (asignatura_id)');
        DB::statement('ALTER TABLE paralelos DROP COLUMN asignatura_ofertada_id');
        DB::statement(<<<'SQL'
            ALTER TABLE paralelos
            ADD CONSTRAINT paralelos_periodo_asignatura_codigo_unique
                UNIQUE (periodo_academico_id, asignatura_id, codigo_paralelo)
        SQL);
        DB::statement('DROP TABLE asignaturas_ofertadas');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-181.');
    }
};
