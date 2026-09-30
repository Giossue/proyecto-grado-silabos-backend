<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['validaciones_silabo', 'resultados_validacion', 'silabos', 'revisiones_silabo', 'aprobaciones', 'reaperturas', 'definiciones_campo'];

    private const COLUMNS = [
        'validaciones_silabo' => [
            'estado_ejecucion_validacion' => 'estado_validacion',
            'version_bloqueo' => 'version_borrador',
        ],
        'resultados_validacion' => ['ejecucion_validacion_id' => 'validacion_silabo_id'],
        'silabos' => [
            'version_bloqueo' => 'version_borrador',
            'contexto_academico_silabo' => 'datos_academicos_silabo',
        ],
        'revisiones_silabo' => [
            'fotografia_revision_silabo' => 'contenido_enviado',
            'huella_sha256' => 'huella_contenido',
            'version_bloqueo_origen' => 'version_borrador',
            'clave_idempotencia' => 'clave_solicitud',
        ],
        'aprobaciones' => [
            'huella_sha256' => 'huella_contenido',
            'aprobacion_base_id' => 'aprobacion_anterior_id',
            'clave_idempotencia' => 'clave_solicitud',
        ],
        'reaperturas' => ['clave_idempotencia' => 'clave_solicitud'],
        'definiciones_campo' => [
            'mostrar_si_definicion_campo' => 'condicion_definicion_campo',
            'origen_maestro_definicion_campo' => 'origen_dato_definicion_campo',
        ],
    ];

    private const RENAMED = [
        'ejecuciones_validacion_' => 'validaciones_silabo_',
        'ejecucion_validacion_id' => 'validacion_silabo_id',
        'aprobacion_base_id' => 'aprobacion_anterior_id',
        'clave_idempotencia' => 'clave_solicitud',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE ejecuciones_validacion RENAME TO validaciones_silabo');
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $old => $new) {
                DB::statement("ALTER TABLE {$table} RENAME COLUMN {$old} TO {$new}");
            }
        }

        $this->renameObjects();
        $this->renameByConvention("contype = 'n'", "t.relname || '_' || a.attname || '_not_null'");
        $this->renameByConvention("contype = 'p'", "t.relname || '_pkey'");
        $this->renameByConvention("contype = 'f' AND cardinality(c.conkey) = 1", "t.relname || '_' || a.attname || '_foreign'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION propagar_clave_condicion() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    UPDATE definiciones_campo SET condicion_definicion_campo = NULL
                    WHERE plantilla_id = OLD.plantilla_id
                      AND condicion_definicion_campo ->> 'key' = OLD.clave_definicion_campo;
                    RETURN OLD;
                END IF;
                IF NEW.clave_definicion_campo IS DISTINCT FROM OLD.clave_definicion_campo THEN
                    UPDATE definiciones_campo
                    SET condicion_definicion_campo = jsonb_set(condicion_definicion_campo, '{key}', to_jsonb(NEW.clave_definicion_campo))
                    WHERE plantilla_id = OLD.plantilla_id
                      AND condicion_definicion_campo ->> 'key' = OLD.clave_definicion_campo;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION validar_condicion_definicion() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.condicion_definicion_campo IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM definiciones_campo d
                    WHERE d.plantilla_id = NEW.plantilla_id
                      AND d.clave_definicion_campo = NEW.condicion_definicion_campo ->> 'key'
                      AND d.id <> NEW.id
                ) THEN
                    RAISE EXCEPTION 'La condición apunta a un campo que no existe en la plantilla' USING ERRCODE = '23503';
                END IF;
                RETURN NULL;
            END;
            $$;

            CREATE OR REPLACE FUNCTION validar_relaciones_flujo() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                silabo_relacionado uuid;
                revision_relacionada uuid;
            BEGIN
                IF TG_TABLE_NAME = 'aprobaciones' THEN
                    SELECT silabo_id INTO silabo_relacionado FROM revisiones_silabo WHERE id = NEW.revision_silabo_id;
                    IF EXISTS (
                        SELECT 1 FROM aprobaciones a
                        JOIN revisiones_silabo r ON r.id = a.revision_silabo_id
                        WHERE r.silabo_id = silabo_relacionado
                          AND a.clave_solicitud = NEW.clave_solicitud
                          AND a.id <> NEW.id
                    ) THEN
                        RAISE EXCEPTION 'La clave de solicitud de la aprobación ya se usó en el sílabo' USING ERRCODE = '23505';
                    END IF;
                ELSIF TG_TABLE_NAME = 'reaperturas' THEN
                    SELECT revision_silabo_id INTO revision_relacionada
                    FROM aprobaciones WHERE id = NEW.aprobacion_id;
                    IF revision_relacionada IS DISTINCT FROM NEW.revision_aprobada_id THEN
                        RAISE EXCEPTION 'La reapertura no coincide con la aprobación vigente' USING ERRCODE = '23514';
                    END IF;
                    SELECT silabo_id INTO silabo_relacionado FROM revisiones_silabo WHERE id = NEW.revision_aprobada_id;
                    IF EXISTS (
                        SELECT 1 FROM reaperturas x
                        JOIN revisiones_silabo r ON r.id = x.revision_aprobada_id
                        WHERE r.silabo_id = silabo_relacionado
                          AND x.clave_solicitud = NEW.clave_solicitud
                          AND x.id <> NEW.id
                    ) THEN
                        RAISE EXCEPTION 'La clave de solicitud de la reapertura ya se usó en el sílabo' USING ERRCODE = '23505';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        throw new LogicException('Los nombres de sílabos, revisiones y plantilla de I-178 no se revierten: restaure el respaldo previo.');
    }

    private function renameObjects(): void
    {
        $tables = "'".implode("','", self::TABLES)."'";

        foreach (DB::select(<<<SQL
            SELECT t.relname AS tabla, c.conname AS nombre
            FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
            WHERE t.relname IN ({$tables}) AND c.contype IN ('c', 'u', 'x')
        SQL) as $row) {
            $new = $this->newName($row->nombre);
            if ($new !== $row->nombre) {
                DB::statement(sprintf('ALTER TABLE %s RENAME CONSTRAINT "%s" TO "%s"', $row->tabla, $row->nombre, $new));
            }
        }

        foreach (DB::select(<<<SQL
            SELECT i.relname AS nombre
            FROM pg_index x
            JOIN pg_class i ON i.oid = x.indexrelid
            JOIN pg_class t ON t.oid = x.indrelid
            WHERE t.relname IN ({$tables})
              AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = i.oid)
        SQL) as $row) {
            $new = $this->newName($row->nombre);
            if ($new !== $row->nombre) {
                DB::statement(sprintf('ALTER INDEX "%s" RENAME TO "%s"', $row->nombre, $new));
            }
        }
    }

    private function newName(string $name): string
    {
        return substr(strtr($name, self::RENAMED), 0, 63);
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
            WHERE n.nspname = 'public' AND t.relname IN ({$tables}) AND c.{$filter}
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
