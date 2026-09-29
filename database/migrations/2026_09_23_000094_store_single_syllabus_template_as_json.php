<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropRevisionTriggers('plantillas_silabo');

        Schema::table('plantillas_silabo', function (Blueprint $table): void {
            $table->jsonb('documento_plantilla')->nullable();
        });

        $this->copyComponentsToDocument();
        $this->detachFieldsFromComponents();
        Schema::drop('componentes_plantilla');

        Schema::rename('plantillas_silabo', 'plantilla_silabo');
        DB::statement('ALTER INDEX IF EXISTS plantillas_silabo_unica RENAME TO plantilla_silabo_unica');
        DB::statement(<<<'SQL'
            ALTER TABLE plantilla_silabo
            ALTER COLUMN documento_plantilla SET NOT NULL,
            ALTER COLUMN documento_plantilla SET DEFAULT '{"version_esquema":1,"secciones":[]}'::jsonb
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE plantilla_silabo
            ADD CONSTRAINT plantilla_silabo_documento_check CHECK (
                jsonb_typeof(documento_plantilla) = 'object'
                AND documento_plantilla->>'version_esquema' = '1'
                AND jsonb_typeof(documento_plantilla->'secciones') = 'array'
            )
            SQL);

        $this->createDocumentIntegrityTriggers();
        $this->createRevisionTriggers('plantilla_silabo');
    }

    public function down(): void
    {
        $this->dropRevisionTriggers('plantilla_silabo');
        $this->dropDocumentIntegrityTriggers();
        DB::statement('ALTER TABLE plantilla_silabo DROP CONSTRAINT IF EXISTS plantilla_silabo_documento_check');
        Schema::rename('plantilla_silabo', 'plantillas_silabo');
        DB::statement('ALTER INDEX IF EXISTS plantilla_silabo_unica RENAME TO plantillas_silabo_unica');

        $this->createComponentsTable();
        $this->copyDocumentToComponents();
        $this->reattachFieldsToComponents();

        Schema::table('plantillas_silabo', function (Blueprint $table): void {
            $table->dropColumn('documento_plantilla');
        });

        $this->createLegacyRevisionTriggers();
    }

    private function copyComponentsToDocument(): void
    {
        foreach (DB::table('plantillas_silabo')->orderBy('id')->get(['id']) as $template) {
            $components = DB::table('componentes_plantilla')
                ->where('plantilla_id', $template->id)
                ->orderBy('posicion_componente_plantilla')
                ->orderBy('id')
                ->get();
            $sections = [];

            foreach ($components->whereNull('componente_padre_id') as $section) {
                $blocks = [];
                foreach ($components->where('componente_padre_id', $section->id) as $block) {
                    $blocks[] = [
                        'id' => $block->id,
                        'clave' => $block->clave_componente_plantilla,
                        'tipo' => $block->tipo_componente_plantilla,
                        'titulo' => $block->titulo_componente_plantilla,
                        'configuracion' => $this->json($block->configuracion_componente_plantilla),
                        'posicion' => (int) $block->posicion_componente_plantilla,
                    ];
                }

                $sections[] = [
                    'id' => $section->id,
                    'clave' => $section->clave_componente_plantilla,
                    'titulo' => $section->titulo_componente_plantilla,
                    'descripcion' => $section->descripcion_componente_plantilla,
                    'posicion' => (int) $section->posicion_componente_plantilla,
                    'bloques' => $blocks,
                ];
            }

            DB::table('plantillas_silabo')->where('id', $template->id)->update([
                'documento_plantilla' => json_encode([
                    'version_esquema' => 1,
                    'secciones' => $sections,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    private function detachFieldsFromComponents(): void
    {
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_componente_plantilla_id_foreign');
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_componente_id_posicion_campo_unique');
        DB::statement('ALTER TABLE definiciones_campo RENAME COLUMN componente_plantilla_id TO bloque_plantilla_id');
        DB::statement(<<<'SQL'
            ALTER TABLE definiciones_campo
            ADD CONSTRAINT definiciones_campo_bloque_id_posicion_unica
            UNIQUE (bloque_plantilla_id, posicion_definicion_campo)
            SQL);
    }

    private function createComponentsTable(): void
    {
        Schema::create('componentes_plantilla', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plantilla_id')->constrained('plantillas_silabo')->cascadeOnDelete();
            $table->uuid('componente_padre_id')->nullable();
            $table->string('clave_componente_plantilla', 120);
            $table->string('tipo_componente_plantilla', 40);
            $table->string('titulo_componente_plantilla', 180);
            $table->text('descripcion_componente_plantilla')->nullable();
            $table->jsonb('configuracion_componente_plantilla')->nullable();
            $table->unsignedSmallInteger('posicion_componente_plantilla');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE componentes_plantilla
            ADD CONSTRAINT componentes_plantilla_componente_padre_id_foreign
            FOREIGN KEY (componente_padre_id) REFERENCES componentes_plantilla(id) ON DELETE CASCADE
            SQL);
        DB::statement('CREATE UNIQUE INDEX componentes_plantilla_raiz_clave_unica ON componentes_plantilla (plantilla_id, clave_componente_plantilla) WHERE componente_padre_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX componentes_plantilla_hijo_clave_unica ON componentes_plantilla (plantilla_id, clave_componente_plantilla) WHERE componente_padre_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX componentes_plantilla_raiz_posicion_unica ON componentes_plantilla (plantilla_id, posicion_componente_plantilla) WHERE componente_padre_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX componentes_plantilla_hijo_posicion_unica ON componentes_plantilla (componente_padre_id, posicion_componente_plantilla) WHERE componente_padre_id IS NOT NULL');
        DB::statement("ALTER TABLE componentes_plantilla ADD CONSTRAINT componentes_plantilla_tipo_check CHECK (tipo_componente_plantilla IN ('seccion', 'grupo', 'campos', 'repetible', 'narrativa', 'flujo'))");
    }

    private function copyDocumentToComponents(): void
    {
        foreach (DB::table('plantillas_silabo')->orderBy('id')->get(['id', 'documento_plantilla']) as $template) {
            $document = $this->json($template->documento_plantilla) ?? [];
            foreach ($document['secciones'] ?? [] as $section) {
                DB::table('componentes_plantilla')->insert([
                    'id' => $section['id'],
                    'plantilla_id' => $template->id,
                    'componente_padre_id' => null,
                    'clave_componente_plantilla' => $section['clave'],
                    'tipo_componente_plantilla' => 'seccion',
                    'titulo_componente_plantilla' => $section['titulo'],
                    'descripcion_componente_plantilla' => $section['descripcion'] ?? null,
                    'configuracion_componente_plantilla' => null,
                    'posicion_componente_plantilla' => $section['posicion'],
                ]);
                foreach ($section['bloques'] ?? [] as $block) {
                    DB::table('componentes_plantilla')->insert([
                        'id' => $block['id'],
                        'plantilla_id' => $template->id,
                        'componente_padre_id' => $section['id'],
                        'clave_componente_plantilla' => $block['clave'],
                        'tipo_componente_plantilla' => $block['tipo'],
                        'titulo_componente_plantilla' => $block['titulo'],
                        'descripcion_componente_plantilla' => null,
                        'configuracion_componente_plantilla' => isset($block['configuracion'])
                            ? json_encode($block['configuracion'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                            : null,
                        'posicion_componente_plantilla' => $block['posicion'],
                    ]);
                }
            }
        }
    }

    private function reattachFieldsToComponents(): void
    {
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_bloque_id_posicion_unica');
        DB::statement('ALTER TABLE definiciones_campo RENAME COLUMN bloque_plantilla_id TO componente_plantilla_id');
        DB::statement(<<<'SQL'
            ALTER TABLE definiciones_campo
            ADD CONSTRAINT definiciones_campo_componente_plantilla_id_foreign
            FOREIGN KEY (componente_plantilla_id) REFERENCES componentes_plantilla(id) ON DELETE CASCADE
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE definiciones_campo
            ADD CONSTRAINT definiciones_campo_componente_id_posicion_campo_unique
            UNIQUE (componente_plantilla_id, posicion_definicion_campo)
            SQL);
    }

    private function dropRevisionTriggers(string $templateTable): void
    {
        DB::statement("DROP TRIGGER IF EXISTS tocar_revision_plantilla_directa ON {$templateTable}");
        if (Schema::hasTable('componentes_plantilla')) {
            DB::statement('DROP TRIGGER IF EXISTS tocar_revision_por_componente ON componentes_plantilla');
        }
        DB::statement('DROP TRIGGER IF EXISTS tocar_revision_por_campo ON definiciones_campo');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla_directa();
            DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla();
            SQL);
    }

    private function createDocumentIntegrityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validar_bloque_definicion_campo()
            RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM plantilla_silabo p
                    CROSS JOIN LATERAL jsonb_array_elements(p.documento_plantilla->'secciones') seccion
                    CROSS JOIN LATERAL jsonb_array_elements(seccion->'bloques') bloque
                    WHERE p.id = NEW.plantilla_id
                      AND bloque->>'id' = NEW.bloque_plantilla_id::text
                ) THEN
                    RAISE EXCEPTION 'El bloque % no pertenece a la plantilla %', NEW.bloque_plantilla_id, NEW.plantilla_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER validar_bloque_definicion_campo
            BEFORE INSERT OR UPDATE OF plantilla_id, bloque_plantilla_id ON definiciones_campo
            FOR EACH ROW EXECUTE FUNCTION validar_bloque_definicion_campo();

            CREATE OR REPLACE FUNCTION validar_bloques_documento_plantilla()
            RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM definiciones_campo campo
                    WHERE campo.plantilla_id = NEW.id
                      AND NOT EXISTS (
                          SELECT 1
                          FROM jsonb_array_elements(NEW.documento_plantilla->'secciones') seccion
                          CROSS JOIN LATERAL jsonb_array_elements(seccion->'bloques') bloque
                          WHERE bloque->>'id' = campo.bloque_plantilla_id::text
                      )
                ) THEN
                    RAISE EXCEPTION 'El documento no contiene todos los bloques referenciados por sus campos'
                        USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER validar_bloques_documento_plantilla
            BEFORE UPDATE OF documento_plantilla ON plantilla_silabo
            FOR EACH ROW EXECUTE FUNCTION validar_bloques_documento_plantilla();
            SQL);
    }

    private function dropDocumentIntegrityTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS validar_bloque_definicion_campo ON definiciones_campo');
        DB::statement('DROP TRIGGER IF EXISTS validar_bloques_documento_plantilla ON plantilla_silabo');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS validar_bloque_definicion_campo();
            DROP FUNCTION IF EXISTS validar_bloques_documento_plantilla();
            SQL);
    }

    private function createRevisionTriggers(string $templateTable): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION tocar_revision_configuracion_plantilla()
            RETURNS trigger AS \$\$
            DECLARE plantilla uuid;
            BEGIN
                plantilla := CASE WHEN TG_OP = 'DELETE' THEN OLD.plantilla_id ELSE NEW.plantilla_id END;
                UPDATE {$templateTable}
                SET revision_configuracion = revision_configuracion + 1
                WHERE id = plantilla;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER tocar_revision_por_campo
            AFTER INSERT OR UPDATE OR DELETE ON definiciones_campo
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();

            CREATE OR REPLACE FUNCTION tocar_revision_configuracion_plantilla_directa()
            RETURNS trigger AS \$\$
            BEGIN
                IF ROW(OLD.mapeo_documento_plantilla, OLD.documento_plantilla)
                   IS DISTINCT FROM
                   ROW(NEW.mapeo_documento_plantilla, NEW.documento_plantilla) THEN
                    NEW.revision_configuracion := OLD.revision_configuracion + 1;
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER tocar_revision_plantilla_directa
            BEFORE UPDATE ON {$templateTable}
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla_directa();
            SQL);
    }

    private function createLegacyRevisionTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tocar_revision_configuracion_plantilla()
            RETURNS trigger AS $$
            DECLARE plantilla uuid;
            BEGIN
                plantilla := CASE WHEN TG_OP = 'DELETE' THEN OLD.plantilla_id ELSE NEW.plantilla_id END;
                UPDATE plantillas_silabo
                SET revision_configuracion = revision_configuracion + 1
                WHERE id = plantilla;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tocar_revision_por_componente
            AFTER INSERT OR UPDATE OR DELETE ON componentes_plantilla
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();
            CREATE TRIGGER tocar_revision_por_campo
            AFTER INSERT OR UPDATE OR DELETE ON definiciones_campo
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();

            CREATE OR REPLACE FUNCTION tocar_revision_configuracion_plantilla_directa()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.mapeo_documento_plantilla IS DISTINCT FROM NEW.mapeo_documento_plantilla THEN
                    NEW.revision_configuracion := OLD.revision_configuracion + 1;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tocar_revision_plantilla_directa
            BEFORE UPDATE ON plantillas_silabo
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla_directa();
            SQL);
    }

    /** @return array<string, mixed>|null */
    private function json(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value)
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : null;
    }
};
