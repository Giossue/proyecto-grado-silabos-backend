<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-178: un sílabo aprobado no se reabre; Coordinación lo elimina. Se retiran las columnas de
 * reapertura de `aprobaciones`, que vuelve a ser totalmente inmutable (ADR 0002).
 * No se revierte: la reversa exige restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE aprobaciones DROP COLUMN causa_reapertura, DROP COLUMN origen_reapertura, DROP COLUMN reabierta_por, DROP COLUMN reabierta_en, DROP COLUMN clave_reapertura');
        DB::statement('DROP TRIGGER proteger_aprobacion ON aprobaciones');
        DB::statement('CREATE TRIGGER proteger_aprobacion BEFORE UPDATE OR DELETE ON aprobaciones FOR EACH ROW EXECUTE FUNCTION proteger_registro_append_only()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_relaciones_flujo() RETURNS trigger AS $$
            DECLARE
                silabo_relacionado uuid;
            BEGIN
                IF TG_TABLE_NAME = 'aprobaciones' THEN
                    SELECT silabo_id INTO silabo_relacionado FROM revisiones_silabo WHERE id = NEW.revision_silabo_id;
                    IF EXISTS (
                        SELECT 1 FROM aprobaciones a
                        JOIN revisiones_silabo r ON r.id = a.revision_silabo_id
                        WHERE r.silabo_id = silabo_relacionado
                          AND a.clave_solicitud = NEW.clave_solicitud
                          AND a.id <> NEW.id
                    ) THEN
                        RAISE EXCEPTION 'La clave de solicitud de la aprobación ya se usó en el sílabo' USING ERRCODE = '23505';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-178.');
    }
};
