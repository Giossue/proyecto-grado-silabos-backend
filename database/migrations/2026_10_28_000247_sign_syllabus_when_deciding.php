<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        $signedFiles = DB::table('firmas_documento')->pluck('archivo_firmado_id')->all();
        DB::statement('DROP TABLE firmas_documento');
        if ($signedFiles !== []) {
            DB::table('archivos')->whereIn('archivo_id', $signedFiles)->delete();
        }
        DB::statement("SET LOCAL app.reinicio_institucional = 'off'");

        DB::statement(<<<'SQL'
            CREATE TABLE firmas_silabo (
                firma_silabo_id uuid PRIMARY KEY,
                silabo_id uuid NOT NULL,
                revision_silabo_id uuid NOT NULL,
                firmado_como varchar(16) NOT NULL,
                usuario_id uuid NOT NULL,
                declaracion_firmada text NOT NULL,
                firma_cms text NOT NULL,
                nombre_en_certificado varchar(255) NOT NULL,
                numero_certificado varchar(255) NOT NULL,
                entidad_emisora varchar(500) NOT NULL,
                certificado_valido_hasta timestamp(0) with time zone NOT NULL,
                firmado_en timestamp(0) with time zone NOT NULL,
                CONSTRAINT firmas_silabo_silabo_id_foreign FOREIGN KEY (silabo_id) REFERENCES silabos (silabo_id) ON DELETE RESTRICT,
                CONSTRAINT firmas_silabo_revision_silabo_id_foreign FOREIGN KEY (revision_silabo_id) REFERENCES revisiones_silabo (revision_silabo_id) ON DELETE RESTRICT,
                CONSTRAINT firmas_silabo_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES usuarios (usuario_id) ON DELETE RESTRICT,
                CONSTRAINT firmas_silabo_firmado_como_check CHECK (firmado_como IN ('docente', 'coordinacion', 'biblioteca')),
                CONSTRAINT firmas_silabo_firmado_como_unico UNIQUE (silabo_id, firmado_como)
            )
            SQL);
        DB::statement('CREATE INDEX firmas_silabo_revision_silabo_id_index ON firmas_silabo (revision_silabo_id)');
        DB::statement('CREATE INDEX firmas_silabo_usuario_id_index ON firmas_silabo (usuario_id)');
        DB::statement(<<<'SQL'
            CREATE TRIGGER proteger_firma_silabo
            BEFORE UPDATE OR DELETE ON firmas_silabo
            FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()
            SQL);

        DB::statement('ALTER TABLE exportaciones_silabo DROP CONSTRAINT exportaciones_silabo_completitud_check');
        DB::statement(<<<'SQL'
            ALTER TABLE exportaciones_silabo ADD CONSTRAINT exportaciones_silabo_completitud_check CHECK (
                estado_exportacion <> 'completado'
                OR (archivo_pdf_id IS NOT NULL AND completado_en IS NOT NULL)
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS firmas_silabo');
        DB::statement(<<<'SQL'
            CREATE TABLE firmas_documento (
                firma_documento_id uuid PRIMARY KEY,
                exportacion_silabo_id uuid NOT NULL REFERENCES exportaciones_silabo (exportacion_silabo_id) ON DELETE RESTRICT,
                firmado_como varchar(16) NOT NULL,
                usuario_id uuid NOT NULL REFERENCES usuarios (usuario_id) ON DELETE RESTRICT,
                archivo_firmado_id uuid NOT NULL REFERENCES archivos (archivo_id) ON DELETE RESTRICT,
                nombre_en_certificado varchar(255) NOT NULL,
                numero_certificado varchar(255) NOT NULL,
                entidad_emisora varchar(500) NOT NULL,
                certificado_valido_hasta timestamp(0) with time zone NOT NULL,
                firmado_en timestamp(0) with time zone NOT NULL,
                CONSTRAINT firmas_documento_firmado_como_check CHECK (firmado_como IN ('docente', 'coordinacion', 'biblioteca')),
                CONSTRAINT firmas_documento_firmado_como_unico UNIQUE (exportacion_silabo_id, firmado_como),
                CONSTRAINT firmas_documento_archivo_firmado_unico UNIQUE (archivo_firmado_id)
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER proteger_firma_documento
            BEFORE UPDATE OR DELETE ON firmas_documento
            FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()
            SQL);
    }
};
