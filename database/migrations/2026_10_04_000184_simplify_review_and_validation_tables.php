<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CORRECTION_COLUMNS = [
        'correccion_justificacion', 'correccion_etapa', 'correccion_solicitada_por', 'correccion_solicitada_en',
    ];

    private const REOPENING_COLUMNS = [
        'causa_reapertura', 'origen_reapertura', 'reabierta_por', 'reabierta_en', 'clave_reapertura',
    ];

    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE revisiones_silabo
            ADD COLUMN correccion_justificacion text NULL,
            ADD COLUMN correccion_etapa varchar(16) NULL,
            ADD COLUMN correccion_solicitada_por uuid NULL,
            ADD COLUMN correccion_solicitada_en timestamptz NULL
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE aprobaciones
            ADD COLUMN causa_reapertura text NULL,
            ADD COLUMN origen_reapertura varchar(16) NULL,
            ADD COLUMN reabierta_por uuid NULL,
            ADD COLUMN reabierta_en timestamptz NULL,
            ADD COLUMN clave_reapertura uuid NULL
        SQL);

        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        DB::statement(<<<'SQL'
            UPDATE revisiones_silabo revision
            SET correccion_justificacion = solicitud.justificacion_solicitud_correccion,
                correccion_etapa = solicitud.etapa_solicitud,
                correccion_solicitada_por = solicitud.solicitado_por,
                correccion_solicitada_en = solicitud.solicitado_en
            FROM solicitudes_correccion solicitud
            WHERE solicitud.revision_silabo_id = revision.id
        SQL);
        DB::statement(<<<'SQL'
            UPDATE aprobaciones aprobacion
            SET causa_reapertura = reapertura.causa,
                origen_reapertura = reapertura.origen_reapertura,
                reabierta_por = reapertura.reabierto_por,
                reabierta_en = reapertura.reabierto_en,
                clave_reapertura = reapertura.clave_solicitud
            FROM reaperturas reapertura
            WHERE reapertura.aprobacion_id = aprobacion.id
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE revisiones_silabo
            ADD CONSTRAINT revisiones_silabo_correccion_solicitada_por_foreign
                FOREIGN KEY (correccion_solicitada_por) REFERENCES usuarios (id) ON DELETE RESTRICT,
            ADD CONSTRAINT revisiones_silabo_correccion_etapa_check
                CHECK (correccion_etapa IN ('biblioteca', 'coordinacion', 'decanato')),
            ADD CONSTRAINT revisiones_silabo_correccion_completa_check
                CHECK (
                    (correccion_justificacion IS NULL AND correccion_etapa IS NULL
                        AND correccion_solicitada_por IS NULL AND correccion_solicitada_en IS NULL)
                    OR (correccion_justificacion IS NOT NULL AND correccion_etapa IS NOT NULL
                        AND correccion_solicitada_por IS NOT NULL AND correccion_solicitada_en IS NOT NULL)
                )
        SQL);
        DB::statement('CREATE INDEX revisiones_silabo_correccion_solicitada_por_index ON revisiones_silabo (correccion_solicitada_por)');
        DB::statement(<<<'SQL'
            ALTER TABLE aprobaciones
            ADD CONSTRAINT aprobaciones_reabierta_por_foreign
                FOREIGN KEY (reabierta_por) REFERENCES usuarios (id) ON DELETE RESTRICT,
            ADD CONSTRAINT aprobaciones_origen_reapertura_check
                CHECK (origen_reapertura IN ('coordinacion', 'biblioteca', 'decanato')),
            ADD CONSTRAINT aprobaciones_reapertura_completa_check
                CHECK (
                    (causa_reapertura IS NULL AND origen_reapertura IS NULL
                        AND reabierta_por IS NULL AND reabierta_en IS NULL AND clave_reapertura IS NULL)
                    OR (causa_reapertura IS NOT NULL AND origen_reapertura IS NOT NULL
                        AND reabierta_por IS NOT NULL AND reabierta_en IS NOT NULL AND clave_reapertura IS NOT NULL)
                )
        SQL);
        DB::statement('CREATE INDEX aprobaciones_reabierta_por_index ON aprobaciones (reabierta_por)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_relaciones_revision() RETURNS trigger AS $$
            DECLARE
                silabo_relacionado uuid;
                numero_anterior integer;
            BEGIN
                IF NEW.revision_anterior_id IS NOT NULL THEN
                    SELECT silabo_id, numero_revision
                    INTO silabo_relacionado, numero_anterior
                    FROM revisiones_silabo
                    WHERE id = NEW.revision_anterior_id;
                    IF silabo_relacionado IS DISTINCT FROM NEW.silabo_id
                       OR NEW.numero_revision <> numero_anterior + 1 THEN
                        RAISE EXCEPTION 'La revisión anterior no pertenece a la secuencia del sílabo' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.numero_revision <> 1 THEN
                    RAISE EXCEPTION 'La primera revisión debe tener número 1' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
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
                    IF NEW.clave_reapertura IS NOT NULL AND EXISTS (
                        SELECT 1 FROM aprobaciones a
                        JOIN revisiones_silabo r ON r.id = a.revision_silabo_id
                        WHERE r.silabo_id = silabo_relacionado
                          AND a.clave_reapertura = NEW.clave_reapertura
                          AND a.id <> NEW.id
                    ) THEN
                        RAISE EXCEPTION 'La clave de solicitud de la reapertura ya se usó en el sílabo' USING ERRCODE = '23505';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_registro_con_campos_unicos() RETURNS trigger AS $$
            DECLARE
                antes jsonb;
                despues jsonb;
                campo text;
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    antes := to_jsonb(OLD);
                    despues := to_jsonb(NEW);
                    FOREACH campo IN ARRAY TG_ARGV LOOP
                        IF antes -> campo = 'null'::jsonb THEN
                            antes := antes - campo;
                            despues := despues - campo;
                        END IF;
                    END LOOP;
                    IF antes = despues THEN
                        RETURN NEW;
                    END IF;
                END IF;
                RAISE EXCEPTION 'El registro histórico es inmutable' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER proteger_revision_silabo ON revisiones_silabo');
        DB::statement(sprintf(
            "CREATE TRIGGER proteger_revision_silabo BEFORE UPDATE OR DELETE ON revisiones_silabo FOR EACH ROW EXECUTE FUNCTION proteger_registro_con_campos_unicos('%s')",
            implode("', '", self::CORRECTION_COLUMNS),
        ));
        DB::statement('DROP TRIGGER proteger_aprobacion ON aprobaciones');
        DB::statement(sprintf(
            "CREATE TRIGGER proteger_aprobacion BEFORE UPDATE OR DELETE ON aprobaciones FOR EACH ROW EXECUTE FUNCTION proteger_registro_con_campos_unicos('%s')",
            implode("', '", self::REOPENING_COLUMNS),
        ));

        DB::statement('ALTER TABLE revisiones_silabo DROP COLUMN reapertura_id');
        DB::statement('DROP TABLE reaperturas');
        DB::statement('DROP TABLE solicitudes_correccion');
        DB::statement('DROP TABLE resultados_validacion');
        DB::statement('DROP TABLE validaciones_silabo');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-176.');
    }
};
