<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS solicitud_correccion_observaciones');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_relaciones_flujo() RETURNS trigger AS $$
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
                          AND a.clave_idempotencia = NEW.clave_idempotencia
                          AND a.id <> NEW.id
                    ) THEN
                        RAISE EXCEPTION 'La clave de idempotencia de la aprobación ya se usó en el sílabo' USING ERRCODE = '23505';
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
                          AND x.clave_idempotencia = NEW.clave_idempotencia
                          AND x.id <> NEW.id
                    ) THEN
                        RAISE EXCEPTION 'La clave de idempotencia de la reapertura ya se usó en el sílabo' USING ERRCODE = '23505';
                    END IF;
                ELSIF TG_TABLE_NAME = 'transiciones_silabo' AND NEW.revision_silabo_id IS NOT NULL THEN
                    SELECT silabo_id INTO silabo_relacionado FROM revisiones_silabo WHERE id = NEW.revision_silabo_id;
                    IF silabo_relacionado IS DISTINCT FROM NEW.silabo_id THEN
                        RAISE EXCEPTION 'La transición y la revisión pertenecen a sílabos distintos' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE solicitud_correccion_observaciones (
                id uuid PRIMARY KEY,
                solicitud_correccion_id uuid NOT NULL REFERENCES solicitudes_correccion(id) ON DELETE RESTRICT,
                observacion_revision_id uuid NOT NULL REFERENCES observaciones_revision(id) ON DELETE RESTRICT,
                CONSTRAINT solicitud_observacion_unica UNIQUE (solicitud_correccion_id, observacion_revision_id)
            )
            SQL);
        DB::statement('CREATE INDEX solicitud_correccion_observaciones_observacion_revision_id_index ON solicitud_correccion_observaciones (observacion_revision_id)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_relaciones_flujo() RETURNS trigger AS $$
            DECLARE
                silabo_relacionado uuid;
                revision_relacionada uuid;
            BEGIN
                IF TG_TABLE_NAME = 'solicitud_correccion_observaciones' THEN
                    SELECT revision_silabo_id INTO revision_relacionada FROM solicitudes_correccion WHERE id = NEW.solicitud_correccion_id;
                    SELECT revision_silabo_id INTO silabo_relacionado FROM observaciones_revision WHERE id = NEW.observacion_revision_id;
                    IF revision_relacionada IS DISTINCT FROM silabo_relacionado THEN
                        RAISE EXCEPTION 'La observación no pertenece a la revisión solicitada' USING ERRCODE = '23514';
                    END IF;
                ELSIF TG_TABLE_NAME = 'aprobaciones' THEN
                    SELECT silabo_id INTO silabo_relacionado FROM revisiones_silabo WHERE id = NEW.revision_silabo_id;
                    IF EXISTS (
                        SELECT 1 FROM aprobaciones a
                        JOIN revisiones_silabo r ON r.id = a.revision_silabo_id
                        WHERE r.silabo_id = silabo_relacionado
                          AND a.clave_idempotencia = NEW.clave_idempotencia
                          AND a.id <> NEW.id
                    ) THEN
                        RAISE EXCEPTION 'La clave de idempotencia de la aprobación ya se usó en el sílabo' USING ERRCODE = '23505';
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
                          AND x.clave_idempotencia = NEW.clave_idempotencia
                          AND x.id <> NEW.id
                    ) THEN
                        RAISE EXCEPTION 'La clave de idempotencia de la reapertura ya se usó en el sílabo' USING ERRCODE = '23505';
                    END IF;
                ELSIF TG_TABLE_NAME = 'transiciones_silabo' AND NEW.revision_silabo_id IS NOT NULL THEN
                    SELECT silabo_id INTO silabo_relacionado FROM revisiones_silabo WHERE id = NEW.revision_silabo_id;
                    IF silabo_relacionado IS DISTINCT FROM NEW.silabo_id THEN
                        RAISE EXCEPTION 'La transición y la revisión pertenecen a sílabos distintos' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER validar_solicitud_observacion
            BEFORE INSERT OR UPDATE ON solicitud_correccion_observaciones
            FOR EACH ROW EXECUTE FUNCTION validar_relaciones_flujo()
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO solicitud_correccion_observaciones (id, solicitud_correccion_id, observacion_revision_id)
            SELECT gen_random_uuid(), s.id, o.id
            FROM solicitudes_correccion s
            JOIN observaciones_revision o ON o.revision_silabo_id = s.revision_silabo_id
            SQL);
    }
};
