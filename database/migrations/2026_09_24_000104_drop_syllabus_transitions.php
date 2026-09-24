<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-136: transiciones_silabo duplicaba eventos_auditoria y la aplicación nunca la
 * leía. Cada cambio de estado del sílabo queda en eventos_auditoria con
 * from_state/to_state en sus metadatos.
 *
 * Es irreversible: el historial eliminado solo se recupera desde el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DROP TABLE elimina también sus triggers.
        DB::statement('DROP TABLE IF EXISTS transiciones_silabo');

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
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('I-136 elimina el historial de transiciones_silabo y no admite reversión automática. Restaure el respaldo previo.');
    }
};
