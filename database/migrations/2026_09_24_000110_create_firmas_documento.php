<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-139. Firma electrónica en el navegador: cada fila es una firma aceptada sobre el PDF
 * de un artefacto de exportación. El orden es fijo (docente y después coordinación) y
 * cada rol firma una sola vez por artefacto. El PDF firmado es un objeto privado nuevo;
 * el certificado se describe para auditoría, nunca se guarda la llave.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE firmas_documento (
                id uuid PRIMARY KEY,
                artefacto_exportacion_id uuid NOT NULL REFERENCES artefactos_exportacion (id) ON DELETE RESTRICT,
                rol_firma varchar(16) NOT NULL,
                usuario_id uuid NOT NULL REFERENCES usuarios (id) ON DELETE RESTRICT,
                objeto_almacenado_id uuid NOT NULL REFERENCES objetos_almacenados (id) ON DELETE RESTRICT,
                certificado_titular varchar(255) NOT NULL,
                certificado_serie varchar(255) NOT NULL,
                certificado_emisor varchar(500) NOT NULL,
                certificado_vence_en timestamptz NOT NULL,
                huella_sha256 char(64) NOT NULL,
                firmado_en timestamptz NOT NULL,
                CONSTRAINT firmas_documento_rol_check CHECK (rol_firma IN ('docente', 'coordinacion')),
                CONSTRAINT firmas_documento_rol_unico UNIQUE (artefacto_exportacion_id, rol_firma),
                CONSTRAINT firmas_documento_objeto_unico UNIQUE (objeto_almacenado_id)
            )
            SQL);
        // Toda llave foránea encabeza un índice (I-133); las otras dos ya lo hacen por
        // sus restricciones únicas.
        DB::statement('CREATE INDEX firmas_documento_usuario_id_index ON firmas_documento (usuario_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS firmas_documento');
    }
};
