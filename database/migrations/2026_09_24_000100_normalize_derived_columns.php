<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I-132. Elimina datos derivados que podían divergir de su fuente:
 *
 * - `asignaturas.total_horas_asignatura` pasa a columna generada ACD + APE + AA (la misma
 *   fórmula que `CurriculumSystemFields::totalHours`). Antes de convertirla, el saldo que
 *   el total registraba por encima de sus componentes se conserva en `horas_pae`, de modo
 *   que ninguna fila pierde horas (POR VALIDAR con la UEB la ubicación del saldo).
 * - `fechas_limite_convocatoria` era una copia por carrera de `inicia_en` y `entrega_en`
 *   de la convocatoria institucional; la prórroga ya se aplica a todo el proceso.
 * - `plantilla_id` en sílabos, convocatorias institucionales, artefactos y ejecuciones de
 *   IA solo podía apuntar a la plantilla única (I-25/I-32); cada revisión conserva su copia
 *   de la estructura en `revisiones_silabo.fotografia`.
 */
return new class extends Migration
{
    private const TEMPLATE_TABLES = [
        'silabos' => 'silabos_plantilla_id_foreign',
        'convocatorias_universidad' => 'procesos_silabos_plantilla_id_foreign',
        'artefactos_exportacion' => 'artefactos_exportacion_plantilla_id_foreign',
        'ejecuciones_ia' => 'ejecuciones_ia_plantilla_id_foreign',
    ];

    public function up(): void
    {
        $this->generateSubjectTotalHours();
        $this->dropConvocationDeadlines();
        $this->dropTemplateReferences();
    }

    public function down(): void
    {
        foreach (array_keys(self::TEMPLATE_TABLES) as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN plantilla_id uuid");
            DB::statement("UPDATE {$table} SET plantilla_id = (SELECT id FROM plantilla_silabo LIMIT 1)");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN plantilla_id SET NOT NULL");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT ".self::TEMPLATE_TABLES[$table].' FOREIGN KEY (plantilla_id) REFERENCES plantilla_silabo(id) ON DELETE RESTRICT');
        }
        // Las comprobaciones de plantilla en triggers no se restauran: con una sola
        // plantilla eran siempre verdaderas.

        DB::statement(<<<'SQL'
            CREATE TABLE fechas_limite_convocatoria (
                id uuid PRIMARY KEY,
                convocatoria_id uuid NOT NULL REFERENCES convocatorias_carreras(id) ON DELETE CASCADE,
                etapa_fecha_limite_convocatoria varchar(40) NOT NULL
                    CONSTRAINT fechas_limite_etapa_check CHECK (etapa_fecha_limite_convocatoria IN ('inicio', 'borrador', 'revision', 'correccion')),
                vence_en timestamp(0) with time zone NOT NULL,
                CONSTRAINT fechas_limite_campania_campania_id_etapa_unique UNIQUE (convocatoria_id, etapa_fecha_limite_convocatoria)
            )
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO fechas_limite_convocatoria (id, convocatoria_id, etapa_fecha_limite_convocatoria, vence_en)
            SELECT gen_random_uuid(), c.id, etapa.nombre, CASE etapa.nombre WHEN 'inicio' THEN p.inicia_en ELSE p.entrega_en END
            FROM convocatorias_carreras c
            JOIN convocatorias_universidad p ON p.id = c.proceso_id
            CROSS JOIN (VALUES ('inicio'), ('borrador')) AS etapa(nombre)
        SQL);

        DB::statement('ALTER TABLE asignaturas DROP COLUMN total_horas_asignatura');
        DB::statement('ALTER TABLE asignaturas ADD COLUMN total_horas_asignatura smallint');
        DB::statement('UPDATE asignaturas SET total_horas_asignatura = round(COALESCE(horas_ac, 0) + COALESCE(horas_pae, 0) + COALESCE(horas_aa, 0))::smallint');
    }

    private function generateSubjectTotalHours(): void
    {
        // Conserva el total registrado: el saldo que no explican ACD, APE y AA pasa a APE.
        DB::statement(<<<'SQL'
            UPDATE asignaturas
            SET horas_pae = COALESCE(horas_pae, 0)
                + (total_horas_asignatura - (COALESCE(horas_ac, 0) + COALESCE(horas_pae, 0) + COALESCE(horas_aa, 0)))
            WHERE total_horas_asignatura IS NOT NULL
              AND total_horas_asignatura > COALESCE(horas_ac, 0) + COALESCE(horas_pae, 0) + COALESCE(horas_aa, 0)
        SQL);
        DB::statement('ALTER TABLE asignaturas DROP COLUMN total_horas_asignatura');
        DB::statement(<<<'SQL'
            ALTER TABLE asignaturas ADD COLUMN total_horas_asignatura smallint
                GENERATED ALWAYS AS (round(COALESCE(horas_ac, 0) + COALESCE(horas_pae, 0) + COALESCE(horas_aa, 0))::smallint) STORED
        SQL);
        DB::statement("COMMENT ON COLUMN asignaturas.total_horas_asignatura IS 'Generada: horas_ac + horas_pae + horas_aa (I-132)'");
    }

    private function dropConvocationDeadlines(): void
    {
        // Una prórroga nunca quita tiempo: si alguna copia quedó por delante, la institucional la alcanza.
        DB::statement(<<<'SQL'
            UPDATE convocatorias_universidad p
            SET entrega_en = f.vence_en
            FROM (
                SELECT c.proceso_id, max(d.vence_en) AS vence_en
                FROM fechas_limite_convocatoria d
                JOIN convocatorias_carreras c ON c.id = d.convocatoria_id
                WHERE d.etapa_fecha_limite_convocatoria = 'borrador'
                GROUP BY c.proceso_id
            ) f
            WHERE f.proceso_id = p.id AND f.vence_en > p.entrega_en
        SQL);
        Schema::drop('fechas_limite_convocatoria');
    }

    private function dropTemplateReferences(): void
    {
        $templates = (int) DB::scalar('SELECT count(*) FROM plantilla_silabo');
        if ($templates > 1) {
            throw new RuntimeException('Existe más de una plantilla de sílabo; plantilla_id no puede eliminarse sin perder información.');
        }

        $this->rewriteFunction('validar_ejecucion_ia', [
            "    SELECT plantilla_id INTO plantilla_silabo\n    FROM silabos WHERE id = NEW.silabo_id;\n    SELECT plantilla_id INTO plantilla_campo\n    FROM definiciones_campo WHERE id = NEW.definicion_campo_id;\n\n    IF plantilla_silabo IS DISTINCT FROM NEW.plantilla_id\n       OR plantilla_campo IS DISTINCT FROM NEW.plantilla_id THEN\n        RAISE EXCEPTION 'La ejecución de IA no coincide con la plantilla y campo del sílabo' USING ERRCODE = '23514';\n    END IF;\n\n" => '',
            "           OR NEW.plantilla_id IS DISTINCT FROM OLD.plantilla_id\n" => '',
        ]);
        $this->rewriteFunction('validar_evidencia_ia', [
            ', e.plantilla_id' => '',
            ', plantilla_ejecucion' => '',
            "        SELECT plantilla_id INTO plantilla_campo\n        FROM definiciones_campo WHERE id = NEW.definicion_campo_id;\n        IF plantilla_campo IS DISTINCT FROM plantilla_ejecucion THEN" => '        IF NOT EXISTS (SELECT 1 FROM definiciones_campo WHERE id = NEW.definicion_campo_id) THEN',
        ]);
        // Dos variantes: con o sin `artefactos_exportacion.silabo_id` (2026_09_24_000099).
        $this->rewriteFunction('validar_artefacto_exportacion', [
            "    SELECT s.plantilla_id\n    INTO plantilla_silabo\n    FROM revisiones_silabo r\n    JOIN silabos s ON s.id = r.silabo_id\n    WHERE r.id = NEW.revision_silabo_id;\n    IF plantilla_silabo IS DISTINCT FROM NEW.plantilla_id THEN\n        RAISE EXCEPTION 'El artefacto no coincide con la revisión y plantilla del sílabo' USING ERRCODE = '23514';\n    END IF;\n" => '',
            ', s.plantilla_id' => '',
            ', plantilla_silabo' => '',
            "    JOIN silabos s ON s.id = r.silabo_id\n" => '',
            "\n       OR plantilla_silabo IS DISTINCT FROM NEW.plantilla_id" => '',
        ]);

        foreach (self::TEMPLATE_TABLES as $table => $foreignKey) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$foreignKey}");
            DB::statement("ALTER TABLE {$table} DROP COLUMN plantilla_id");
        }
    }

    /**
     * Edita la función vigente en lugar de reescribirla completa: otras migraciones del
     * mismo lote pueden haber cambiado partes ajenas a `plantilla_id`. Los fragmentos
     * ausentes se omiten, pero al final no puede quedar ninguna referencia a la columna.
     *
     * @param  array<string, string>  $replacements
     */
    private function rewriteFunction(string $name, array $replacements): void
    {
        $definition = (string) DB::scalar('SELECT pg_get_functiondef(?::regproc)', [$name]);
        foreach ($replacements as $search => $replace) {
            $definition = str_replace($search, $replace, $definition);
        }
        if (preg_match('/(NEW|OLD|e|s)\.plantilla_id/', $definition) === 1) {
            throw new RuntimeException("La función {$name} todavía usa plantilla_id.");
        }
        DB::unprepared($definition);
    }
};
