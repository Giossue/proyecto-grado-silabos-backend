<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-182: `envios_biblioteca.enviado_por` y `enviado_en` repetían los de `revisiones_silabo`.
 * No se revierte: la reversa exige restaurar el respaldo previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_envio_biblioteca() RETURNS trigger AS $$
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE'
                   OR OLD.verificado_en IS NOT NULL
                   OR NEW.revision_silabo_id IS DISTINCT FROM OLD.revision_silabo_id THEN
                    RAISE EXCEPTION 'El envío a biblioteca solo admite registrar su verificación una vez' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('ALTER TABLE envios_biblioteca DROP COLUMN enviado_por, DROP COLUMN enviado_en');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-182.');
    }
};
