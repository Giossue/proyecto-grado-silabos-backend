<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'ejecuciones_trabajo' => 'trabajos_en_cola',
        'notificaciones_internas' => 'notificaciones',
        'historial_ia' => 'mensajes_ia',
    ];

    private const COLUMNS = [
        'trabajos_en_cola' => [
            'tipo_ejecucion_trabajo' => 'tipo_trabajo',
            'estado_ejecucion_trabajo' => 'estado_trabajo',
            'resultado_ejecucion_trabajo' => 'resultado_trabajo',
            'entrada_ejecucion_trabajo' => 'datos_entrada',
            'clave_idempotencia' => 'clave_solicitud',
        ],
        'notificaciones' => [
            'tipo_notificacion_interna' => 'tipo_notificacion',
            'titulo_notificacion_interna' => 'titulo_notificacion',
            'mensaje_notificacion_interna' => 'mensaje_notificacion',
            'clave_deduplicacion' => 'clave_unica',
        ],
        'eventos_auditoria' => [
            'actor_usuario_id' => 'usuario_id',
            'accion_evento_auditoria' => 'accion',
            'resultado_evento_auditoria' => 'resultado',
            'metadatos_evento_auditoria' => 'detalles',
        ],
        'mensajes_ia' => [
            'sesion_id' => 'conversacion_id',
            'mensaje_ia' => 'contenido_mensaje',
        ],
    ];

    private const CONSTRAINTS = [
        'trabajos_en_cola' => [
            'ejecuciones_trabajo_estado_check' => 'trabajos_en_cola_estado_trabajo_check',
            'ejecuciones_trabajo_progreso_check' => 'trabajos_en_cola_progreso_check',
            'ejecuciones_trabajo_clave_idempotencia_unique' => 'trabajos_en_cola_clave_solicitud_unique',
        ],
        'notificaciones' => [
            'notificaciones_internas_usuario_id_clave_deduplicacion_unique' => 'notificaciones_usuario_id_clave_unica_unique',
        ],
        'mensajes_ia' => [
            'historial_ia_mensaje_check' => 'mensajes_ia_contenido_mensaje_check',
            'historial_ia_sesion_check' => 'mensajes_ia_conversacion_id_check',
        ],
    ];

    private const INDEXES = [
        'ejecuciones_trabajo_cola_estado_encolado_en_index' => 'trabajos_en_cola_cola_estado_trabajo_encolado_en_index',
        'ejecuciones_trabajo_estado_encolado_en_index' => 'trabajos_en_cola_estado_trabajo_encolado_en_index',
        'ejecuciones_trabajo_tipo_recurso_recurso_id_index' => 'trabajos_en_cola_tipo_recurso_recurso_id_index',
        'notificaciones_internas_usuario_leido_notificado_index' => 'notificaciones_usuario_leido_notificado_index',
        'eventos_auditoria_actor_fecha_idx' => 'eventos_auditoria_usuario_fecha_idx',
        'historial_ia_mensaje_idempotencia_unica' => 'mensajes_ia_mensaje_idempotencia_unica',
        'historial_ia_sesion_idx' => 'mensajes_ia_conversacion_idx',
    ];

    private const FUNCTIONS = [
        'proteger_entrada_ejecucion_trabajo' => 'proteger_datos_entrada_trabajo',
        'proteger_notificacion_interna' => 'proteger_notificacion',
        'proteger_historial_ia' => 'proteger_mensaje_ia',
        'eliminar_historial_ia_de_silabo' => 'eliminar_mensajes_ia_de_silabo',
    ];

    private const TRIGGERS = [
        'trabajos_en_cola' => ['proteger_entrada_ejecucion_trabajo' => 'proteger_datos_entrada_trabajo'],
        'notificaciones' => ['proteger_notificacion_interna' => 'proteger_notificacion'],
        'mensajes_ia' => ['proteger_historial_ia' => 'proteger_mensaje_ia'],
        'silabos' => ['eliminar_historial_ia_de_silabo' => 'eliminar_mensajes_ia_de_silabo'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $old => $new) {
            DB::statement("ALTER TABLE {$old} RENAME TO {$new}");
        }
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $old => $new) {
                DB::statement("ALTER TABLE {$table} RENAME COLUMN {$old} TO {$new}");
            }
        }
        foreach (self::CONSTRAINTS as $table => $names) {
            foreach ($names as $old => $new) {
                DB::statement("ALTER TABLE {$table} RENAME CONSTRAINT {$old} TO {$new}");
            }
        }
        foreach (self::INDEXES as $old => $new) {
            DB::statement("ALTER INDEX {$old} RENAME TO {$new}");
        }
        foreach (self::FUNCTIONS as $old => $new) {
            DB::statement("ALTER FUNCTION {$old}() RENAME TO {$new}");
        }
        foreach (self::TRIGGERS as $table => $names) {
            foreach ($names as $old => $new) {
                DB::statement("ALTER TRIGGER {$old} ON {$table} RENAME TO {$new}");
            }
        }

        $this->replaceFunctions();

        $tables = array_keys(self::COLUMNS);
        $this->renameByConvention($tables, "contype = 'n'", "t.relname || '_' || a.attname || '_not_null'");
        $this->renameByConvention($tables, "contype = 'p'", "t.relname || '_pkey'");
        $this->renameByConvention($tables, "contype = 'f' AND cardinality(c.conkey) = 1", "t.relname || '_' || a.attname || '_foreign'");
    }

    public function down(): void
    {
        throw new LogicException('Los nombres de I-176 no se revierten: restaure el respaldo previo.');
    }

    private function replaceFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_datos_entrada_trabajo() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
            BEGIN
                IF NEW.datos_entrada IS DISTINCT FROM OLD.datos_entrada THEN
                    RAISE EXCEPTION 'Los datos de entrada de un trabajo en cola son inmutables' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION proteger_notificacion() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE'
                   OR NEW.usuario_id IS DISTINCT FROM OLD.usuario_id
                   OR NEW.clave_unica IS DISTINCT FROM OLD.clave_unica
                   OR NEW.tipo_notificacion IS DISTINCT FROM OLD.tipo_notificacion
                   OR NEW.titulo_notificacion IS DISTINCT FROM OLD.titulo_notificacion
                   OR NEW.mensaje_notificacion IS DISTINCT FROM OLD.mensaje_notificacion
                   OR NEW.tipo_recurso IS DISTINCT FROM OLD.tipo_recurso
                   OR NEW.recurso_id IS DISTINCT FROM OLD.recurso_id
                   OR NEW.notificado_en IS DISTINCT FROM OLD.notificado_en THEN
                    RAISE EXCEPTION 'El contenido de la notificación es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION proteger_mensaje_ia() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'El historial de IA no puede eliminarse' USING ERRCODE = '23514';
                END IF;
                IF OLD.contenido_mensaje ->> 'estado' NOT IN ('pendiente', 'en_ejecucion') THEN
                    RAISE EXCEPTION 'Un mensaje de IA terminal es inmutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id
                   OR NEW.conversacion_id IS DISTINCT FROM OLD.conversacion_id
                   OR NEW.enviado_en IS DISTINCT FROM OLD.enviado_en
                   OR NEW.contenido_mensaje -> 'tipo' IS DISTINCT FROM OLD.contenido_mensaje -> 'tipo'
                   OR NEW.contenido_mensaje -> 'entrada' IS DISTINCT FROM OLD.contenido_mensaje -> 'entrada' THEN
                    RAISE EXCEPTION 'La entrada fijada de un mensaje de IA es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION eliminar_mensajes_ia_de_silabo() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
            BEGIN
                DELETE FROM mensajes_ia WHERE conversacion_id LIKE 'silabo:' || OLD.id::text || ':%';
                RETURN OLD;
            END;
            $$;
        SQL);
    }

    /** @param list<string> $tables */
    private function renameByConvention(array $tables, string $filter, string $expected): void
    {
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $rows = DB::select(<<<SQL
            SELECT t.relname AS tabla, c.conname AS actual, left({$expected}, 63) AS esperado
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = c.conkey[1]
            WHERE n.nspname = 'public' AND t.relkind = 'r' AND t.relname IN ({$placeholders}) AND c.{$filter}
        SQL, $tables);
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
