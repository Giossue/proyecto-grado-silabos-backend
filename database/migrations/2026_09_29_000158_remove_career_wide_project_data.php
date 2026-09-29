<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS valores_curriculares_ambito_valido ON valores_curriculares;
                DROP FUNCTION IF EXISTS validar_ambito_valor_curricular();

                INSERT INTO valores_curriculares (id, concepto_id, carrera_id, asignatura_id, valor_curricular, evidencia_curricular, confirmado_por, confirmado_en)
                SELECT gen_random_uuid(), v.concepto_id, v.carrera_id, a.id, v.valor_curricular, v.evidencia_curricular, v.confirmado_por, v.confirmado_en
                FROM valores_curriculares v
                JOIN asignaturas a ON a.carrera_id = v.carrera_id
                WHERE v.asignatura_id IS NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM valores_curriculares own
                        WHERE own.concepto_id = v.concepto_id AND own.asignatura_id = a.id
                    );
                DELETE FROM valores_curriculares WHERE asignatura_id IS NULL;

                DROP INDEX IF EXISTS valores_curriculares_carrera_unico;
                DROP INDEX IF EXISTS valores_curriculares_asignatura_unico;
                ALTER TABLE valores_curriculares ALTER COLUMN asignatura_id SET NOT NULL;
                ALTER TABLE valores_curriculares ADD CONSTRAINT valores_curriculares_asignatura_unico UNIQUE (concepto_id, asignatura_id);

                CREATE OR REPLACE FUNCTION proteger_forma_concepto_curricular() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.clave_concepto IS DISTINCT FROM OLD.clave_concepto
                        OR NEW.tipo_concepto IS DISTINCT FROM OLD.tipo_concepto
                        OR NEW.columnas_concepto IS DISTINCT FROM OLD.columnas_concepto THEN
                        RAISE EXCEPTION 'La identidad y estructura del concepto curricular son inmutables' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$;
                ALTER TABLE conceptos_curriculares DROP CONSTRAINT IF EXISTS conceptos_curriculares_ambito_valido;
                ALTER TABLE conceptos_curriculares DROP COLUMN ambito_concepto;

                CREATE OR REPLACE FUNCTION validar_tipo_valor_curricular() RETURNS trigger LANGUAGE plpgsql AS $$
                DECLARE tipo text;
                BEGIN
                    SELECT tipo_concepto INTO tipo FROM conceptos_curriculares WHERE id = NEW.concepto_id;
                    IF (tipo = 'texto' AND jsonb_typeof(NEW.valor_curricular) <> 'string')
                        OR (tipo IN ('lista', 'tabla') AND jsonb_typeof(NEW.valor_curricular) <> 'array') THEN
                        RAISE EXCEPTION 'El valor no corresponde al tipo del concepto curricular' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER valores_curriculares_tipo_valido BEFORE INSERT OR UPDATE ON valores_curriculares
                    FOR EACH ROW EXECUTE FUNCTION validar_tipo_valor_curricular();
                SQL);
        });
    }

    public function down(): void
    {
        throw new LogicException('El retiro de los datos de toda la carrera (I-160) no se revierte: restaure el respaldo previo.');
    }
};
