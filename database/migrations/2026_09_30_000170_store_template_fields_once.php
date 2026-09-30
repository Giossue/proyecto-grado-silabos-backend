<?php

use App\Modules\Configuration\Application\TemplateFieldAttributes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('campos_plantilla')->get(['id', 'configuracion_campo_plantilla']) as $block) {
            $configuration = json_decode((string) $block->configuracion_campo_plantilla, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($configuration)) {
                continue;
            }
            DB::table('campos_plantilla')->where('id', $block->id)->update([
                'configuracion_campo_plantilla' => json_encode(
                    TemplateFieldAttributes::store($configuration),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            ]);
        }

        DB::statement('ALTER TABLE secciones_plantilla ADD CONSTRAINT secciones_plantilla_id_plantilla_unica UNIQUE (id, plantilla_id)');
        DB::statement('ALTER TABLE campos_plantilla ADD CONSTRAINT campos_plantilla_id_plantilla_unica UNIQUE (id, plantilla_id)');
        DB::statement('ALTER TABLE campos_plantilla ADD CONSTRAINT campos_plantilla_seccion_misma_plantilla_foreign FOREIGN KEY (seccion_plantilla_id, plantilla_id) REFERENCES secciones_plantilla (id, plantilla_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_campo_misma_plantilla_foreign FOREIGN KEY (campo_plantilla_id, plantilla_id) REFERENCES campos_plantilla (id, plantilla_id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION validar_condicion_definicion() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.mostrar_si_definicion_campo IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM definiciones_campo d
                    WHERE d.plantilla_id = NEW.plantilla_id
                      AND d.clave_definicion_campo = NEW.mostrar_si_definicion_campo ->> 'key'
                      AND d.id <> NEW.id
                ) THEN
                    RAISE EXCEPTION 'La condición apunta a un campo que no existe en la plantilla' USING ERRCODE = '23503';
                END IF;
                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER validar_condicion_definicion
            AFTER INSERT OR UPDATE OF mostrar_si_definicion_campo ON definiciones_campo
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION validar_condicion_definicion();

            CREATE FUNCTION propagar_clave_condicion() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    UPDATE definiciones_campo SET mostrar_si_definicion_campo = NULL
                    WHERE plantilla_id = OLD.plantilla_id
                      AND mostrar_si_definicion_campo ->> 'key' = OLD.clave_definicion_campo;
                    RETURN OLD;
                END IF;
                IF NEW.clave_definicion_campo IS DISTINCT FROM OLD.clave_definicion_campo THEN
                    UPDATE definiciones_campo
                    SET mostrar_si_definicion_campo = jsonb_set(mostrar_si_definicion_campo, '{key}', to_jsonb(NEW.clave_definicion_campo))
                    WHERE plantilla_id = OLD.plantilla_id
                      AND mostrar_si_definicion_campo ->> 'key' = OLD.clave_definicion_campo;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER propagar_clave_condicion
            AFTER DELETE OR UPDATE OF clave_definicion_campo ON definiciones_campo
            FOR EACH ROW EXECUTE FUNCTION propagar_clave_condicion();
        SQL);

        foreach (['envios_biblioteca' => ['enviado_en', 'verificado_en'], 'firmas_documento' => ['firmado_en', 'certificado_vence_en']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE timestamp(0) with time zone");
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('La normalización de campos de la plantilla (I-168) no se revierte: restaure el respaldo previo.');
    }
};
