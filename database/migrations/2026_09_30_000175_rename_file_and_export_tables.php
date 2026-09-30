<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'objetos_almacenados' => 'archivos',
        'artefactos_exportacion' => 'exportaciones_silabo',
        'fuentes_academicas' => 'documentos_importacion',
    ];

    private const COLUMNS = [
        'archivos' => [
            'nombre_logico' => 'nombre_archivo',
            'mime' => 'tipo_archivo',
            'clasificacion' => 'categoria',
            'propietario_usuario_id' => 'subido_por',
            'ruta_interna' => 'ruta_archivo',
            'huella_sha256' => 'huella_archivo',
        ],
        'facultades' => ['logo_objeto_id' => 'logo_archivo_id'],
        'exportaciones_silabo' => [
            'objeto_docx_id' => 'archivo_word_id',
            'objeto_pdf_id' => 'archivo_pdf_id',
            'version_renderizador' => 'version_generador',
            'estado_artefacto_exportacion' => 'estado_exportacion',
            'ejecucion_trabajo_id' => 'trabajo_id',
            'clave_idempotencia' => 'clave_solicitud',
        ],
        'firmas_documento' => [
            'artefacto_exportacion_id' => 'exportacion_silabo_id',
            'rol_firma' => 'firmado_como',
            'objeto_almacenado_id' => 'archivo_firmado_id',
            'certificado_titular' => 'nombre_en_certificado',
            'certificado_serie' => 'numero_certificado',
            'certificado_emisor' => 'entidad_emisora',
            'certificado_vence_en' => 'certificado_valido_hasta',
            'huella_sha256' => 'huella_archivo',
        ],
        'documentos_importacion' => [
            'nombre_fuente_academica' => 'nombre_documento',
            'fuente_academica_activa' => 'documento_activo',
            'objeto_pdf_id' => 'archivo_pdf_id',
            'estado_ingesta_fuente' => 'estado_lectura',
            'ingesta_actualizada_en' => 'lectura_actualizada_en',
            'documento_fuente_academica' => 'contenido_documento',
        ],
    ];

    private const CONSTRAINTS = [
        'archivos' => [
            'objetos_almacenados_disco_ruta_interna_unique' => 'archivos_disco_ruta_archivo_unique',
        ],
        'exportaciones_silabo' => [
            'artefactos_exportacion_ejecucion_trabajo_id_unique' => 'exportaciones_silabo_trabajo_id_unique',
            'artefactos_exportacion_objeto_docx_id_unique' => 'exportaciones_silabo_archivo_word_id_unique',
            'artefactos_exportacion_objeto_pdf_id_unique' => 'exportaciones_silabo_archivo_pdf_id_unique',
            'artefactos_exportacion_revision_idempotencia_unica' => 'exportaciones_silabo_revision_clave_solicitud_unica',
            'artefactos_exportacion_completitud_check' => 'exportaciones_silabo_completitud_check',
            'artefactos_exportacion_estado_check' => 'exportaciones_silabo_estado_check',
        ],
        'firmas_documento' => [
            'firmas_documento_objeto_unico' => 'firmas_documento_archivo_firmado_unico',
            'firmas_documento_rol_unico' => 'firmas_documento_firmado_como_unico',
            'firmas_documento_rol_check' => 'firmas_documento_firmado_como_check',
        ],
        'documentos_importacion' => [
            'fuentes_academicas_carrera_id_nombre_fuente_unique' => 'documentos_importacion_carrera_id_nombre_documento_unique',
            'fuentes_academicas_documento_check' => 'documentos_importacion_contenido_check',
            'fuentes_academicas_estado_ingesta_check' => 'documentos_importacion_estado_lectura_check',
            'fuentes_academicas_sin_texto_vacio_check' => 'documentos_importacion_sin_texto_vacio_check',
        ],
    ];

    private const INDEXES = [
        'artefactos_exportacion_revision_estado_solicitado_index' => 'exportaciones_silabo_revision_estado_solicitado_index',
        'artefactos_exportacion_solicitado_por_index' => 'exportaciones_silabo_solicitado_por_index',
        'facultades_logo_objeto_id_index' => 'facultades_logo_archivo_id_index',
        'fuentes_academicas_objeto_pdf_id_index' => 'documentos_importacion_archivo_pdf_id_index',
        'objetos_almacenados_carrera_id_index' => 'archivos_carrera_id_index',
        'objetos_almacenados_clasificacion_almacenado_en_index' => 'archivos_categoria_almacenado_en_index',
        'objetos_almacenados_huella_sha256_index' => 'archivos_huella_archivo_index',
        'objetos_almacenados_propietario_usuario_id_index' => 'archivos_subido_por_index',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $old => $new) {
            DB::statement("ALTER TABLE IF EXISTS {$old} RENAME TO {$new}");
        }
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $old => $new) {
                if ($this->columnExists($table, $old)) {
                    DB::statement("ALTER TABLE {$table} RENAME COLUMN {$old} TO {$new}");
                }
            }
        }
        foreach (self::CONSTRAINTS as $table => $names) {
            foreach ($names as $old => $new) {
                $this->renameConstraint($table, $old, $new);
            }
        }
        foreach (self::INDEXES as $old => $new) {
            DB::statement("ALTER INDEX IF EXISTS {$old} RENAME TO {$new}");
        }

        DB::statement('DROP TRIGGER IF EXISTS validar_artefacto_exportacion ON exportaciones_silabo');
        DB::statement('DROP FUNCTION IF EXISTS validar_artefacto_exportacion()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_exportacion_silabo() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una exportación del sílabo no puede eliminarse' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.estado_exportacion = 'completado' AND NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION 'Una exportación completada es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER validar_exportacion_silabo BEFORE INSERT OR DELETE OR UPDATE ON exportaciones_silabo FOR EACH ROW EXECUTE FUNCTION validar_exportacion_silabo()');
        DB::statement('ALTER TRIGGER proteger_objeto_almacenado ON archivos RENAME TO proteger_archivo');

        $this->renameByConvention("contype = 'n'", "t.relname || '_' || a.attname || '_not_null'");
        $this->renameByConvention("contype = 'p'", "t.relname || '_pkey'");
        $this->renameByConvention("contype = 'f' AND cardinality(c.conkey) = 1", "t.relname || '_' || a.attname || '_foreign'");
    }

    public function down(): void
    {
        throw new LogicException('Los nombres de archivos, exportaciones y firmas de I-175 no se revierten: restaure el respaldo previo.');
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::selectOne(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?",
            [$table, $column],
        ) !== null;
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
                AND t.relname IN ('archivos', 'exportaciones_silabo', 'firmas_documento', 'documentos_importacion', 'facultades')
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
