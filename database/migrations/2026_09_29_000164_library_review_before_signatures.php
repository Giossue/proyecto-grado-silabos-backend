<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('envios_biblioteca')->exists()) {
            throw new RuntimeException('Hay envíos a biblioteca registrados: revise su migración a la revisión antes de continuar.');
        }
        DB::statement('DROP TABLE envios_biblioteca');
        DB::statement(<<<'SQL'
            CREATE TABLE envios_biblioteca (
                id uuid PRIMARY KEY,
                revision_silabo_id uuid NOT NULL REFERENCES revisiones_silabo (id) ON DELETE RESTRICT,
                enviado_por uuid NOT NULL REFERENCES usuarios (id) ON DELETE RESTRICT,
                enviado_en timestamptz NOT NULL,
                verificado_por uuid NULL REFERENCES usuarios (id) ON DELETE RESTRICT,
                verificado_en timestamptz NULL,
                CONSTRAINT envios_biblioteca_revision_unica UNIQUE (revision_silabo_id),
                CONSTRAINT envios_biblioteca_verificacion_completa CHECK ((verificado_por IS NULL) = (verificado_en IS NULL))
            )
            SQL);
        DB::statement('CREATE INDEX envios_biblioteca_enviado_por_index ON envios_biblioteca (enviado_por)');
        DB::statement('CREATE INDEX envios_biblioteca_verificado_por_index ON envios_biblioteca (verificado_por)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_envio_biblioteca() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE'
                   OR OLD.verificado_en IS NOT NULL
                   OR NEW.revision_silabo_id IS DISTINCT FROM OLD.revision_silabo_id
                   OR NEW.enviado_por IS DISTINCT FROM OLD.enviado_por
                   OR NEW.enviado_en IS DISTINCT FROM OLD.enviado_en THEN
                    RAISE EXCEPTION 'El envío a biblioteca solo admite registrar su verificación una vez' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER proteger_envio_biblioteca
            BEFORE UPDATE OR DELETE ON envios_biblioteca
            FOR EACH ROW EXECUTE FUNCTION proteger_envio_biblioteca();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS envios_biblioteca');
        DB::statement('DROP FUNCTION IF EXISTS proteger_envio_biblioteca()');
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
        DB::statement('CREATE TRIGGER proteger_envio_biblioteca BEFORE UPDATE OR DELETE ON envios_biblioteca FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()');
    }
};
