<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE asignaciones_rol DROP CONSTRAINT asignaciones_rol_rol_valido_check');
        DB::statement('ALTER TABLE asignaciones_rol DROP CONSTRAINT asignaciones_rol_alcance_valido_check');
        DB::statement(<<<'SQL'
            ALTER TABLE asignaciones_rol
            ADD CONSTRAINT asignaciones_rol_rol_valido_check
            CHECK (rol IN ('administrador', 'coordinador', 'docente', 'bibliotecario'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE asignaciones_rol
            ADD CONSTRAINT asignaciones_rol_alcance_valido_check
            CHECK (
                (rol IN ('administrador', 'bibliotecario') AND carrera_id IS NULL)
                OR (rol IN ('coordinador', 'docente') AND carrera_id IS NOT NULL)
            )
        SQL);

        DB::statement('ALTER TABLE firmas_documento DROP CONSTRAINT firmas_documento_rol_check');
        DB::statement(<<<'SQL'
            ALTER TABLE firmas_documento
            ADD CONSTRAINT firmas_documento_rol_check
            CHECK (rol_firma IN ('docente', 'coordinacion', 'biblioteca'))
        SQL);

        DB::statement("ALTER TABLE reaperturas ADD COLUMN origen_reapertura varchar(16) NOT NULL DEFAULT 'coordinacion'");
        DB::statement(<<<'SQL'
            ALTER TABLE reaperturas
            ADD CONSTRAINT reaperturas_origen_check
            CHECK (origen_reapertura IN ('coordinacion', 'biblioteca'))
        SQL);

        DB::statement('ALTER TABLE aprobaciones ADD COLUMN aprobacion_base_id uuid NULL REFERENCES aprobaciones (id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX aprobaciones_aprobacion_base_id_index ON aprobaciones (aprobacion_base_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE envios_biblioteca (
                id uuid PRIMARY KEY,
                artefacto_exportacion_id uuid NOT NULL REFERENCES artefactos_exportacion (id) ON DELETE RESTRICT,
                enviado_por uuid NOT NULL REFERENCES usuarios (id) ON DELETE RESTRICT,
                enviado_en timestamptz NOT NULL,
                CONSTRAINT envios_biblioteca_artefacto_unico UNIQUE (artefacto_exportacion_id)
            )
            SQL);
        DB::statement('CREATE INDEX envios_biblioteca_enviado_por_index ON envios_biblioteca (enviado_por)');
        DB::statement(<<<'SQL'
            CREATE TRIGGER proteger_envio_biblioteca
            BEFORE UPDATE OR DELETE ON envios_biblioteca
            FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS envios_biblioteca');
        DB::statement('DROP INDEX IF EXISTS aprobaciones_aprobacion_base_id_index');
        DB::statement('ALTER TABLE aprobaciones DROP COLUMN IF EXISTS aprobacion_base_id');
        DB::statement('ALTER TABLE reaperturas DROP CONSTRAINT IF EXISTS reaperturas_origen_check');
        DB::statement('ALTER TABLE reaperturas DROP COLUMN IF EXISTS origen_reapertura');
        DB::statement('ALTER TABLE firmas_documento DROP CONSTRAINT firmas_documento_rol_check');
        DB::statement("ALTER TABLE firmas_documento ADD CONSTRAINT firmas_documento_rol_check CHECK (rol_firma IN ('docente', 'coordinacion'))");
        DB::statement('ALTER TABLE asignaciones_rol DROP CONSTRAINT asignaciones_rol_rol_valido_check');
        DB::statement('ALTER TABLE asignaciones_rol DROP CONSTRAINT asignaciones_rol_alcance_valido_check');
        DB::statement("ALTER TABLE asignaciones_rol ADD CONSTRAINT asignaciones_rol_rol_valido_check CHECK (rol IN ('administrador', 'coordinador', 'docente'))");
        DB::statement(<<<'SQL'
            ALTER TABLE asignaciones_rol
            ADD CONSTRAINT asignaciones_rol_alcance_valido_check
            CHECK (
                (rol = 'administrador' AND carrera_id IS NULL)
                OR (rol IN ('coordinador', 'docente') AND carrera_id IS NOT NULL)
            )
        SQL);
    }
};
