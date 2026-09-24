<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-135: la persistencia de IA pasa de once tablas a dos, con el patrón estándar de
 * memoria de agente + almacén vectorial:
 *
 * - `historial_ia`: cada fila es un mensaje JSON de una sesión (`sesion_id`). Las
 *   sugerencias por campo, la revisión integral, las decisiones de la persona y el chat
 *   son mensajes de sesiones distintas.
 * - `documentos_ia`: fragmentos de las fuentes académicas con metadatos y embedding.
 *
 * La base remota no tenía filas en las tablas de IA; los fragmentos locales, si existen,
 * se copian al almacén vectorial conservando su embedding.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        DB::statement(<<<'SQL'
            CREATE TABLE historial_ia (
                id uuid PRIMARY KEY,
                sesion_id text NOT NULL,
                mensaje_ia jsonb NOT NULL,
                enviado_en timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT historial_ia_sesion_check CHECK (
                    sesion_id ~ '^silabo:[0-9a-f-]{36}:(usuario:[0-9a-f-]{36}|campo:[0-9a-f-]{36}|revision)$'
                ),
                CONSTRAINT historial_ia_mensaje_check CHECK (
                    jsonb_typeof(mensaje_ia) = 'object'
                    AND jsonb_typeof(mensaje_ia -> 'entrada') = 'object'
                    AND mensaje_ia ->> 'tipo' IN ('analisis', 'revision', 'decision', 'mensaje', 'ocultamiento')
                    AND mensaje_ia ->> 'estado' IN (
                        'pendiente', 'en_ejecucion', 'completada', 'no_concluyente', 'parcial',
                        'fallida', 'completado', 'fallido', 'registrada'
                    )
                )
            )
            SQL);
        // Índice principal de la memoria: una sesión se lee completa y en orden; el
        // operador de patrón permite además purgar por prefijo `silabo:{id}:`.
        DB::statement('CREATE INDEX historial_ia_sesion_idx ON historial_ia (sesion_id text_pattern_ops, enviado_en)');
        // Invariantes de idempotencia que antes vivían en restricciones de cada tabla.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX historial_ia_analisis_idempotencia_unica ON historial_ia (
                sesion_id,
                (mensaje_ia -> 'entrada' ->> 'clave_idempotencia'),
                (coalesce(mensaje_ia -> 'entrada' ->> 'revision_id', ''))
            ) WHERE mensaje_ia ->> 'tipo' = 'analisis'
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX historial_ia_revision_version_unica ON historial_ia (
                sesion_id, (mensaje_ia -> 'entrada' ->> 'version_bloqueo_origen')
            ) WHERE mensaje_ia ->> 'tipo' = 'revision'
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX historial_ia_mensaje_idempotencia_unica ON historial_ia (
                sesion_id, (mensaje_ia -> 'entrada' ->> 'clave_idempotencia')
            ) WHERE mensaje_ia ->> 'tipo' = 'mensaje' AND mensaje_ia ->> 'rol' = 'usuario'
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX historial_ia_decision_unica ON historial_ia (
                (mensaje_ia -> 'entrada' ->> 'recomendacion_id'),
                (mensaje_ia -> 'entrada' ->> 'usuario_id'),
                (mensaje_ia -> 'entrada' ->> 'decision')
            ) WHERE mensaje_ia ->> 'tipo' = 'decision'
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX historial_ia_aplicada_unica ON historial_ia (
                (mensaje_ia -> 'entrada' ->> 'recomendacion_id')
            ) WHERE mensaje_ia ->> 'tipo' = 'decision' AND mensaje_ia -> 'entrada' ->> 'decision' = 'aplicada'
            SQL);

        // La entrada fijada nunca cambia y un mensaje terminal es inmutable. Solo el
        // reinicio institucional confirmado (I-118) puede borrar historial.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_historial_ia() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'El historial de IA no puede eliminarse' USING ERRCODE = '23514';
                END IF;
                IF OLD.mensaje_ia ->> 'estado' NOT IN ('pendiente', 'en_ejecucion') THEN
                    RAISE EXCEPTION 'Un mensaje de IA terminal es inmutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id
                   OR NEW.sesion_id IS DISTINCT FROM OLD.sesion_id
                   OR NEW.enviado_en IS DISTINCT FROM OLD.enviado_en
                   OR NEW.mensaje_ia -> 'tipo' IS DISTINCT FROM OLD.mensaje_ia -> 'tipo'
                   OR NEW.mensaje_ia -> 'entrada' IS DISTINCT FROM OLD.mensaje_ia -> 'entrada' THEN
                    RAISE EXCEPTION 'La entrada fijada de un mensaje de IA es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER proteger_historial_ia
            BEFORE UPDATE OR DELETE ON historial_ia
            FOR EACH ROW EXECUTE FUNCTION proteger_historial_ia()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE documentos_ia (
                id uuid PRIMARY KEY,
                contenido_documento text NOT NULL,
                metadatos_documento jsonb NOT NULL DEFAULT '{}'::jsonb,
                embedding vector(1536),
                CONSTRAINT documentos_ia_metadatos_check CHECK (
                    jsonb_typeof(metadatos_documento) = 'object'
                    AND (metadatos_documento ->> 'fuente_academica_id') IS NOT NULL
                )
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX documentos_ia_embedding_hnsw_idx
            ON documentos_ia USING hnsw (embedding vector_cosine_ops)
            WHERE embedding IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX documentos_ia_fuente_idx
            ON documentos_ia ((metadatos_documento ->> 'fuente_academica_id'), ((metadatos_documento ->> 'posicion')::integer))
            SQL);
        // Respaldo léxico cuando no hay embeddings disponibles.
        DB::statement(<<<'SQL'
            CREATE INDEX documentos_ia_busqueda_idx ON documentos_ia USING gin (
                to_tsvector('spanish'::regconfig, coalesce(metadatos_documento ->> 'titulo', '') || ' ' || contenido_documento)
            )
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO documentos_ia (id, contenido_documento, metadatos_documento, embedding)
            SELECT f.id, f.contenido_fragmento,
                   jsonb_strip_nulls(jsonb_build_object(
                       'fuente_academica_id', f.fuente_academica_id::text,
                       'carrera_id', s.carrera_id::text,
                       'titulo', f.titulo_fragmento,
                       'ruta_encabezados', f.ruta_encabezados,
                       'posicion', f.posicion_fragmento,
                       'pagina_inicio', f.pagina_inicio,
                       'pagina_fin', f.pagina_fin,
                       'bloques_origen', f.bloques_origen,
                       'huella', f.huella_fragmento
                   )),
                   f.embedding
            FROM fragmentos_fuente_academica f
            JOIN fuentes_academicas s ON s.id = f.fuente_academica_id
            SQL);

        // Sin llaves foráneas dentro del JSON, dos triggers reemplazan las cascadas.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION eliminar_documentos_ia_de_fuente() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                DELETE FROM documentos_ia WHERE metadatos_documento ->> 'fuente_academica_id' = OLD.id::text;
                RETURN OLD;
            END;
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER eliminar_documentos_ia_de_fuente
            AFTER DELETE ON fuentes_academicas
            FOR EACH ROW EXECUTE FUNCTION eliminar_documentos_ia_de_fuente()
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION eliminar_historial_ia_de_silabo() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                DELETE FROM historial_ia WHERE sesion_id LIKE 'silabo:' || OLD.id::text || ':%';
                RETURN OLD;
            END;
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER eliminar_historial_ia_de_silabo
            AFTER DELETE ON silabos
            FOR EACH ROW EXECUTE FUNCTION eliminar_historial_ia_de_silabo()
            SQL);

        // Sus triggers se van con las tablas; las funciones de validación eran exclusivas.
        foreach ([
            'citas_mensaje_ia', 'evidencias_mensaje_ia', 'mensajes_ia', 'conversaciones_ia',
            'retroalimentacion_ia', 'recomendacion_evidencias_ia', 'recomendaciones_ia',
            'evidencias_ia', 'ejecuciones_ia', 'revisiones_ia_silabo', 'fragmentos_fuente_academica',
        ] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
        foreach ([
            'validar_recomendacion_ia', 'validar_ejecucion_ia', 'validar_evidencia_ia',
            'validar_recomendacion_evidencia_ia', 'validar_retroalimentacion_ia',
            'proteger_revision_ia_silabo',
        ] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('La consolidación de la persistencia de IA no admite reversión automática.');
    }
};
