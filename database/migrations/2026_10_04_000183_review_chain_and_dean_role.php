<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-173: revisión en cadena (biblioteca → coordinación → decanato) y rol Decano por facultad.
 * No se revierte: la reversa exige restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Rol decano con alcance por facultad.
        DB::statement('ALTER TABLE asignaciones_rol ADD COLUMN facultad_id uuid NULL REFERENCES facultades (id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX asignaciones_rol_facultad_id_index ON asignaciones_rol (facultad_id)');
        DB::statement('ALTER TABLE asignaciones_rol DROP CONSTRAINT asignaciones_rol_rol_valido_check');
        DB::statement('ALTER TABLE asignaciones_rol DROP CONSTRAINT asignaciones_rol_alcance_valido_check');
        DB::statement(<<<'SQL'
            ALTER TABLE asignaciones_rol
            ADD CONSTRAINT asignaciones_rol_rol_valido_check
            CHECK (rol IN ('administrador', 'coordinador', 'docente', 'bibliotecario', 'decano'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE asignaciones_rol
            ADD CONSTRAINT asignaciones_rol_alcance_valido_check
            CHECK (
                (rol IN ('administrador', 'bibliotecario') AND carrera_id IS NULL AND facultad_id IS NULL)
                OR (rol IN ('coordinador', 'docente') AND carrera_id IS NOT NULL AND facultad_id IS NULL)
                OR (rol = 'decano' AND carrera_id IS NULL AND facultad_id IS NOT NULL)
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX asignaciones_rol_decano_facultad_unica
            ON asignaciones_rol (facultad_id)
            WHERE rol = 'decano' AND asignacion_rol_activa
        SQL);

        // Cuarta firma y cuarta fuente de reapertura.
        DB::statement('ALTER TABLE firmas_documento DROP CONSTRAINT firmas_documento_firmado_como_check');
        DB::statement(<<<'SQL'
            ALTER TABLE firmas_documento
            ADD CONSTRAINT firmas_documento_firmado_como_check
            CHECK (firmado_como IN ('docente', 'coordinacion', 'biblioteca', 'decanato'))
        SQL);
        DB::statement('ALTER TABLE reaperturas DROP CONSTRAINT reaperturas_origen_check');
        DB::statement(<<<'SQL'
            ALTER TABLE reaperturas
            ADD CONSTRAINT reaperturas_origen_check
            CHECK (origen_reapertura IN ('coordinacion', 'biblioteca', 'decanato'))
        SQL);

        // Cada devolución registra desde qué etapa se pidió.
        DB::statement("ALTER TABLE solicitudes_correccion ADD COLUMN etapa_solicitud varchar(16) NOT NULL DEFAULT 'coordinacion'");
        DB::statement(<<<'SQL'
            ALTER TABLE solicitudes_correccion
            ADD CONSTRAINT solicitudes_correccion_etapa_check
            CHECK (etapa_solicitud IN ('biblioteca', 'coordinacion', 'decanato'))
        SQL);

        // Aprobación de coordinación: una por revisión, inmutable, entre biblioteca y decanato.
        DB::statement(<<<'SQL'
            CREATE TABLE revisiones_coordinacion (
                id uuid PRIMARY KEY,
                revision_silabo_id uuid NOT NULL REFERENCES revisiones_silabo (id) ON DELETE RESTRICT,
                revisado_por uuid NOT NULL REFERENCES usuarios (id) ON DELETE RESTRICT,
                revisado_en timestamptz NOT NULL,
                CONSTRAINT revisiones_coordinacion_revision_unica UNIQUE (revision_silabo_id)
            )
            SQL);
        DB::statement('CREATE INDEX revisiones_coordinacion_revisado_por_index ON revisiones_coordinacion (revisado_por)');
        DB::statement(<<<'SQL'
            CREATE TRIGGER proteger_revision_coordinacion
            BEFORE UPDATE OR DELETE ON revisiones_coordinacion
            FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()
            SQL);

        // Datos en curso: lo que estaba en revisión vuelve a empezar por biblioteca; los envíos
        // pendientes de sílabos ya aprobados (flujo anterior) se retiran.
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        DB::statement(<<<'SQL'
            DELETE FROM envios_biblioteca
            WHERE verificado_en IS NULL
              AND revision_silabo_id IN (
                SELECT r.id FROM revisiones_silabo r JOIN silabos s ON s.id = r.silabo_id WHERE s.estado_silabo <> 'en_revision'
              )
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO envios_biblioteca (id, revision_silabo_id, enviado_por, enviado_en)
            SELECT gen_random_uuid(), r.id, r.enviado_por, r.enviado_en
            FROM revisiones_silabo r
            JOIN silabos s ON s.id = r.silabo_id
            WHERE s.estado_silabo = 'en_revision'
              AND r.numero_revision = (SELECT MAX(numero_revision) FROM revisiones_silabo x WHERE x.silabo_id = r.silabo_id)
              AND NOT EXISTS (SELECT 1 FROM envios_biblioteca e WHERE e.revision_silabo_id = r.id)
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La migración 000183 no se revierte: restaure el respaldo previo.');
    }
};
