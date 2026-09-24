<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I-131: retira datos derivables alrededor del sílabo.
 *
 * - `alcances_silabo` → `silabos.paralelo_id`: cada sílabo cubre exactamente un paralelo.
 * - `silabos.carrera_id`: se deriva de la convocatoria de carrera.
 * - `silabo_id` en aprobaciones, reaperturas, solicitudes de corrección y artefactos de
 *   exportación: se deriva de la revisión.
 *
 * Todo paso valida que la copia coincida con el origen antes de retirarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->moveScopeToSyllabus();
        $this->dropSyllabusCareer();
        $this->dropRevisionChildSyllabus();
    }

    private function moveScopeToSyllabus(): void
    {
        $invalid = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS total FROM silabos s
            WHERE (SELECT COUNT(*) FROM alcances_silabo a WHERE a.silabo_id = s.id) <> 1
               OR EXISTS (
                   SELECT 1 FROM alcances_silabo a
                   JOIN paralelos p ON p.id = a.paralelo_id
                   WHERE a.silabo_id = s.id
                     AND (a.convocatoria_id <> s.convocatoria_id
                          OR a.programacion_asignatura_id <> p.programacion_asignatura_id)
               )
            SQL);
        if ((int) $invalid->total > 0) {
            throw new RuntimeException('No se puede normalizar: cada sílabo debe tener exactamente un alcance coherente.');
        }

        DB::statement('ALTER TABLE silabos ADD COLUMN paralelo_id uuid NULL');
        DB::statement(<<<'SQL'
            UPDATE silabos s SET paralelo_id = a.paralelo_id
            FROM alcances_silabo a WHERE a.silabo_id = s.id
            SQL);
        DB::statement('ALTER TABLE silabos ALTER COLUMN paralelo_id SET NOT NULL');
        DB::statement('ALTER TABLE silabos ADD CONSTRAINT silabos_paralelo_id_foreign FOREIGN KEY (paralelo_id) REFERENCES paralelos(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE silabos ADD CONSTRAINT silabos_convocatoria_id_paralelo_id_unique UNIQUE (convocatoria_id, paralelo_id)');
        DB::statement('CREATE INDEX silabos_paralelo_id_index ON silabos (paralelo_id)');

        DB::statement('DROP VIEW docentes_vigentes_silabo');
        DB::statement('DROP TABLE alcances_silabo');
        DB::statement(<<<'SQL'
            CREATE VIEW docentes_vigentes_silabo AS
            SELECT DISTINCT
                silabos.id AS silabo_id,
                asignaciones_rol.usuario_id,
                asignaciones_rol.id AS asignacion_rol_id,
                asignaciones_paralelo.id AS asignacion_paralelo_id,
                silabos.paralelo_id
            FROM silabos
            JOIN asignaciones_paralelo
                ON asignaciones_paralelo.paralelo_id = silabos.paralelo_id
                AND asignaciones_paralelo.docente_paralelo_activo
            JOIN asignaciones_rol
                ON asignaciones_rol.id = asignaciones_paralelo.asignacion_rol_id
                AND asignaciones_rol.asignacion_rol_activa
                AND asignaciones_rol.rol = 'docente'
            SQL);
    }

    private function dropSyllabusCareer(): void
    {
        $invalid = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS total FROM silabos s
            JOIN convocatorias_carreras c ON c.id = s.convocatoria_id
            WHERE c.carrera_id <> s.carrera_id
            SQL);
        if ((int) $invalid->total > 0) {
            throw new RuntimeException('No se puede retirar silabos.carrera_id: difiere de la carrera de su convocatoria.');
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_evidencia_ia() RETURNS trigger AS $$
            DECLARE
                carrera_fuente uuid;
                carrera_silabo uuid;
                fuente_activa boolean;
                convocatoria_silabo uuid;
                estado_ejecucion text;
                plantilla_ejecucion uuid;
                plantilla_campo uuid;
                fuente_fragmento uuid;
            BEGIN
                SELECT s.convocatoria_id, c.carrera_id, e.estado_ejecucion_ia, e.plantilla_id
                INTO convocatoria_silabo, carrera_silabo, estado_ejecucion, plantilla_ejecucion
                FROM ejecuciones_ia e
                JOIN silabos s ON s.id = e.silabo_id
                JOIN convocatorias_carreras c ON c.id = s.convocatoria_id
                WHERE e.id = NEW.ejecucion_ia_id;

                IF estado_ejecucion IS DISTINCT FROM 'pendiente' THEN
                    RAISE EXCEPTION 'La evidencia solo puede fijarse mientras la ejecución está pendiente' USING ERRCODE = '23514';
                END IF;

                IF NEW.tipo_evidencia_ia = 'fuente_academica' THEN
                    SELECT fuente_academica_activa, carrera_id INTO fuente_activa, carrera_fuente
                    FROM fuentes_academicas WHERE id = NEW.fuente_academica_id;
                    IF NEW.fragmento_fuente_academica_id IS NOT NULL THEN
                        SELECT fuente_academica_id INTO fuente_fragmento
                        FROM fragmentos_fuente_academica WHERE id = NEW.fragmento_fuente_academica_id;
                    END IF;
                    IF carrera_fuente IS DISTINCT FROM carrera_silabo
                       OR fuente_activa IS DISTINCT FROM TRUE
                       OR (NEW.fragmento_fuente_academica_id IS NOT NULL AND fuente_fragmento IS DISTINCT FROM NEW.fuente_academica_id)
                       OR NOT EXISTS (
                           SELECT 1 FROM fuentes_convocatoria
                           WHERE convocatoria_id = convocatoria_silabo
                             AND fuente_academica_id = NEW.fuente_academica_id
                       ) THEN
                        RAISE EXCEPTION 'La evidencia debe citar una fuente activa fijada por la convocatoria' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.tipo_evidencia_ia = 'seccion_silabo' THEN
                    SELECT plantilla_id INTO plantilla_campo
                    FROM definiciones_campo WHERE id = NEW.definicion_campo_id;
                    IF plantilla_campo IS DISTINCT FROM plantilla_ejecucion THEN
                        RAISE EXCEPTION 'La evidencia interna debe pertenecer a la plantilla del sílabo' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    RAISE EXCEPTION 'Tipo de evidencia no admitido' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement('ALTER TABLE silabos DROP COLUMN carrera_id');
    }

    private function dropRevisionChildSyllabus(): void
    {
        $checks = [
            'aprobaciones' => 'SELECT COUNT(*) AS total FROM aprobaciones x JOIN revisiones_silabo r ON r.id = x.revision_silabo_id WHERE r.silabo_id <> x.silabo_id',
            'reaperturas' => 'SELECT COUNT(*) AS total FROM reaperturas x JOIN revisiones_silabo r ON r.id = x.revision_aprobada_id WHERE r.silabo_id <> x.silabo_id',
            'solicitudes_correccion' => 'SELECT COUNT(*) AS total FROM solicitudes_correccion x JOIN revisiones_silabo r ON r.id = x.revision_silabo_id WHERE r.silabo_id <> x.silabo_id',
            'artefactos_exportacion' => 'SELECT COUNT(*) AS total FROM artefactos_exportacion x JOIN revisiones_silabo r ON r.id = x.revision_silabo_id WHERE r.silabo_id <> x.silabo_id',
        ];
        foreach ($checks as $table => $sql) {
            if ((int) DB::selectOne($sql)->total > 0) {
                throw new RuntimeException("No se puede retirar {$table}.silabo_id: difiere del sílabo de su revisión.");
            }
        }

        // Las claves de idempotencia seguían siendo únicas por sílabo; se comprueban por la
        // revisión porque la columna desaparece. Las acciones bloquean el sílabo antes.
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
                IF NEW.reapertura_id IS NOT NULL THEN
                    SELECT r.silabo_id INTO silabo_relacionado
                    FROM reaperturas x
                    JOIN revisiones_silabo r ON r.id = x.revision_aprobada_id
                    WHERE x.id = NEW.reapertura_id;
                    IF silabo_relacionado IS DISTINCT FROM NEW.silabo_id THEN
                        RAISE EXCEPTION 'La reapertura no pertenece al sílabo' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_artefacto_exportacion() RETURNS trigger AS $$
            DECLARE
                revision_existente uuid;
                plantilla_silabo uuid;
            BEGIN
                IF reinicio_institucional_habilitado() THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un artefacto de exportación no puede eliminarse' USING ERRCODE = '23514';
                END IF;
                SELECT r.id, s.plantilla_id
                INTO revision_existente, plantilla_silabo
                FROM revisiones_silabo r
                JOIN silabos s ON s.id = r.silabo_id
                WHERE r.id = NEW.revision_silabo_id;
                IF revision_existente IS NULL
                   OR plantilla_silabo IS DISTINCT FROM NEW.plantilla_id THEN
                    RAISE EXCEPTION 'El artefacto no coincide con la revisión y plantilla del sílabo' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.estado_artefacto_exportacion = 'completado' AND NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION 'Un artefacto completado es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        foreach (array_keys($checks) as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN silabo_id");
        }
        DB::statement('CREATE INDEX reaperturas_revision_aprobada_id_index ON reaperturas (revision_aprobada_id)');
        DB::statement('CREATE INDEX artefactos_exportacion_revision_estado_solicitado_index ON artefactos_exportacion (revision_silabo_id, estado_artefacto_exportacion, solicitado_en)');
    }

    public function down(): void
    {
        throw new RuntimeException('I-131 no revierte la normalización de sílabos y registros de revisión.');
    }
};
