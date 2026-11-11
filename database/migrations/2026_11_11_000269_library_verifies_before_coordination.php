<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE envios_biblioteca
            ADD COLUMN verificado_por uuid NULL REFERENCES usuarios (usuario_id) ON DELETE RESTRICT,
            ADD COLUMN verificado_en timestamptz NULL,
            ADD COLUMN verificacion_heredada boolean NOT NULL DEFAULT false,
            ADD CONSTRAINT envios_biblioteca_verificacion_completa CHECK ((verificado_por IS NULL) = (verificado_en IS NULL)),
            ADD CONSTRAINT envios_biblioteca_herencia_verificada CHECK (NOT verificacion_heredada OR verificado_en IS NOT NULL)
        SQL);
        DB::statement('CREATE INDEX envios_biblioteca_verificado_por_index ON envios_biblioteca (verificado_por)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_envio_biblioteca() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE'
                   OR OLD.verificado_en IS NOT NULL
                   OR NEW.revision_silabo_id IS DISTINCT FROM OLD.revision_silabo_id
                   OR NEW.verificacion_heredada IS DISTINCT FROM OLD.verificacion_heredada THEN
                    RAISE EXCEPTION 'El envío a biblioteca solo admite registrar su verificación una vez' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            UPDATE envios_biblioteca envio
            SET verificado_por = aprobacion.aprobado_por, verificado_en = aprobacion.aprobado_en
            FROM aprobaciones aprobacion
            WHERE aprobacion.revision_silabo_id = envio.revision_silabo_id
        SQL);

        $unsent = DB::table('silabos as silabo')
            ->join('revisiones_silabo as revision', 'revision.silabo_id', '=', 'silabo.silabo_id')
            ->where('silabo.estado_silabo', 'en_revision')
            ->whereRaw('revision.numero_revision = (SELECT max(ultima.numero_revision) FROM revisiones_silabo ultima WHERE ultima.silabo_id = silabo.silabo_id)')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('envios_biblioteca as envio')->whereColumn('envio.revision_silabo_id', 'revision.revision_silabo_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('aprobaciones as aprobacion')->whereColumn('aprobacion.revision_silabo_id', 'revision.revision_silabo_id'))
            ->pluck('revision.revision_silabo_id');
        foreach ($unsent as $revisionId) {
            DB::table('envios_biblioteca')->insert(['envio_biblioteca_id' => (string) Str::uuid(), 'revision_silabo_id' => $revisionId]);
        }
    }

    public function down(): void
    {
        $newFlow = DB::selectOne(<<<'SQL'
            SELECT count(*) AS total FROM envios_biblioteca envio
            LEFT JOIN aprobaciones aprobacion ON aprobacion.revision_silabo_id = envio.revision_silabo_id
            WHERE envio.verificacion_heredada
               OR (envio.verificado_en IS NOT NULL AND aprobacion.aprobacion_id IS NULL)
               OR (envio.verificado_en IS NOT NULL AND aprobacion.aprobado_por <> envio.verificado_por)
        SQL);
        if ((int) $newFlow->total > 0) {
            throw new RuntimeException('Hay sílabos verificados por Biblioteca antes de Coordinación o aprobados por Coordinación. El orden anterior no puede representarlos; restaure el respaldo previo.');
        }

        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        DB::statement(<<<'SQL'
            DELETE FROM envios_biblioteca envio
            USING revisiones_silabo revision
            WHERE revision.revision_silabo_id = envio.revision_silabo_id
              AND envio.verificado_en IS NULL
              AND NOT EXISTS (SELECT 1 FROM revisiones_coordinacion revisada WHERE revisada.revision_silabo_id = revision.revision_silabo_id)
              AND NOT EXISTS (
                  SELECT 1 FROM revisiones_silabo anterior
                  WHERE anterior.revision_silabo_id = revision.revision_anterior_id AND anterior.correccion_etapa = 'biblioteca'
              )
        SQL);
        DB::statement("SET LOCAL app.reinicio_institucional = 'off'");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_envio_biblioteca() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                RAISE EXCEPTION 'El envío a biblioteca es inmutable' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP INDEX envios_biblioteca_verificado_por_index');
        DB::statement('ALTER TABLE envios_biblioteca DROP COLUMN verificado_por, DROP COLUMN verificado_en, DROP COLUMN verificacion_heredada');
    }
};
