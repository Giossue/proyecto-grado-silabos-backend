<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropTemplateRevisionTriggers();
        $this->createComponentsTable();
        $this->copySectionsAndBlocks();
        $this->moveFieldDefinitions();
        $this->dropLegacyStructureTables();
        $this->simplifyTemplateRoot();
        $this->removeCompletionPercentages();
        $this->createTemplateRevisionTriggers();
    }

    public function down(): void
    {
        $this->dropTemplateRevisionTriggers();
        $this->restoreLegacyStructureTables();
        $this->restoreTemplateRoot();
        $this->restoreCompletionPercentages();
        $this->createLegacyTemplateRevisionTriggers();
    }

    private function dropTemplateRevisionTriggers(): void
    {
        if (Schema::hasTable('plantillas_silabo')) {
            DB::statement('DROP TRIGGER IF EXISTS tocar_revision_plantilla_directa ON plantillas_silabo');
        }
        if (Schema::hasTable('componentes_plantilla')) {
            DB::statement('DROP TRIGGER IF EXISTS tocar_revision_por_componente ON componentes_plantilla');
        }
        if (Schema::hasTable('definiciones_campo')) {
            DB::statement('DROP TRIGGER IF EXISTS tocar_revision_por_campo ON definiciones_campo');
        }
        if (Schema::hasTable('bloques_plantilla')) {
            DB::statement('DROP TRIGGER IF EXISTS tocar_revision_por_bloque ON bloques_plantilla');
        }
        if (Schema::hasTable('secciones_plantilla')) {
            DB::statement('DROP TRIGGER IF EXISTS tocar_revision_por_seccion ON secciones_plantilla');
        }
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla_directa();
            DROP FUNCTION IF EXISTS tocar_revision_configuracion_plantilla();
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

        DB::statement(
            'ALTER TABLE componentes_plantilla ADD CONSTRAINT componentes_plantilla_componente_padre_id_foreign
             FOREIGN KEY (componente_padre_id) REFERENCES componentes_plantilla(id) ON DELETE CASCADE',
        );
        DB::statement(
            'CREATE UNIQUE INDEX componentes_plantilla_raiz_clave_unica
             ON componentes_plantilla (plantilla_id, clave_componente_plantilla)
             WHERE componente_padre_id IS NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX componentes_plantilla_hijo_clave_unica
             ON componentes_plantilla (plantilla_id, clave_componente_plantilla)
             WHERE componente_padre_id IS NOT NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX componentes_plantilla_raiz_posicion_unica
             ON componentes_plantilla (plantilla_id, posicion_componente_plantilla)
             WHERE componente_padre_id IS NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX componentes_plantilla_hijo_posicion_unica
             ON componentes_plantilla (componente_padre_id, posicion_componente_plantilla)
             WHERE componente_padre_id IS NOT NULL',
        );
        DB::statement(
            "ALTER TABLE componentes_plantilla
             ADD CONSTRAINT componentes_plantilla_tipo_check
             CHECK (tipo_componente_plantilla IN ('seccion', 'grupo', 'campos', 'repetible', 'narrativa', 'flujo'))",
        );
    }

    private function copySectionsAndBlocks(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO componentes_plantilla (
                id,
                plantilla_id,
                componente_padre_id,
                clave_componente_plantilla,
                tipo_componente_plantilla,
                titulo_componente_plantilla,
                descripcion_componente_plantilla,
                configuracion_componente_plantilla,
                posicion_componente_plantilla
            )
            SELECT
                id,
                plantilla_id,
                NULL,
                clave_seccion_plantilla,
                'seccion',
                titulo_seccion_plantilla,
                descripcion_seccion_plantilla,
                NULL,
                posicion_seccion_plantilla
            FROM secciones_plantilla
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO componentes_plantilla (
                id,
                plantilla_id,
                componente_padre_id,
                clave_componente_plantilla,
                tipo_componente_plantilla,
                titulo_componente_plantilla,
                descripcion_componente_plantilla,
                configuracion_componente_plantilla,
                posicion_componente_plantilla
            )
            SELECT
                id,
                plantilla_id,
                seccion_plantilla_id,
                clave_bloque_plantilla,
                tipo_bloque_plantilla,
                titulo_bloque_plantilla,
                NULL,
                configuracion_bloque_plantilla,
                posicion_bloque_plantilla
            FROM bloques_plantilla
            SQL);
    }

    private function moveFieldDefinitions(): void
    {
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_bloque_plantilla_id_foreign');
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_bloque_id_posicion_campo_unique');
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_bloque_plantilla_id_posicion_unique');
        DB::statement('ALTER TABLE definiciones_campo RENAME COLUMN bloque_plantilla_id TO componente_plantilla_id');
        DB::statement('ALTER TABLE definiciones_campo DROP COLUMN obligatorio');
        DB::statement(
            'ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_componente_plantilla_id_foreign
             FOREIGN KEY (componente_plantilla_id) REFERENCES componentes_plantilla(id) ON DELETE CASCADE',
        );
        DB::statement(
            'ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_componente_id_posicion_campo_unique
             UNIQUE (componente_plantilla_id, posicion_definicion_campo)',
        );
    }

    private function dropLegacyStructureTables(): void
    {
        Schema::drop('bloques_plantilla');
        Schema::drop('secciones_plantilla');
    }

    private function simplifyTemplateRoot(): void
    {
        Schema::table('plantillas_silabo', function (Blueprint $table): void {
            $table->dropColumn([
                'nombre_plantilla_silabo',
                'descripcion_plantilla_silabo',
                'plantilla_silabo_activa',
            ]);
        });
    }

    private function removeCompletionPercentages(): void
    {
        DB::statement('ALTER TABLE silabos DROP CONSTRAINT IF EXISTS silabos_completitud_check');
        Schema::table('silabos', function (Blueprint $table): void {
            $table->dropColumn('porcentaje_completitud');
        });
        Schema::table('ejecuciones_validacion', function (Blueprint $table): void {
            $table->dropColumn('porcentaje_completitud');
        });
    }

    private function createTemplateRevisionTriggers(): void
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

    private function restoreLegacyStructureTables(): void
    {
        Schema::create('secciones_plantilla', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plantilla_id')->constrained('plantillas_silabo')->cascadeOnDelete();
            $table->string('clave_seccion_plantilla', 100);
            $table->string('titulo_seccion_plantilla', 180);
            $table->text('descripcion_seccion_plantilla')->nullable();
            $table->unsignedSmallInteger('posicion_seccion_plantilla');
            $table->unique(['plantilla_id', 'clave_seccion_plantilla']);
            $table->unique(['plantilla_id', 'posicion_seccion_plantilla']);
        });

        Schema::create('bloques_plantilla', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('seccion_plantilla_id')->constrained('secciones_plantilla')->cascadeOnDelete();
            $table->foreignUuid('plantilla_id')->constrained('plantillas_silabo')->cascadeOnDelete();
            $table->string('clave_bloque_plantilla', 100);
            $table->string('tipo_bloque_plantilla', 40);
            $table->string('titulo_bloque_plantilla', 180);
            $table->jsonb('configuracion_bloque_plantilla')->nullable();
            $table->unsignedSmallInteger('posicion_bloque_plantilla');
            $table->unique(['plantilla_id', 'clave_bloque_plantilla']);
            $table->unique(['seccion_plantilla_id', 'posicion_bloque_plantilla']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO secciones_plantilla (
                id, plantilla_id, clave_seccion_plantilla, titulo_seccion_plantilla,
                descripcion_seccion_plantilla, posicion_seccion_plantilla
            )
            SELECT id, plantilla_id, clave_componente_plantilla,
                   titulo_componente_plantilla, descripcion_componente_plantilla,
                   posicion_componente_plantilla
            FROM componentes_plantilla
            WHERE componente_padre_id IS NULL
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO bloques_plantilla (
                id, plantilla_id, seccion_plantilla_id, clave_bloque_plantilla,
                tipo_bloque_plantilla, titulo_bloque_plantilla,
                configuracion_bloque_plantilla, posicion_bloque_plantilla
            )
            SELECT id, plantilla_id, componente_padre_id, clave_componente_plantilla,
                   tipo_componente_plantilla, titulo_componente_plantilla,
                   configuracion_componente_plantilla, posicion_componente_plantilla
            FROM componentes_plantilla
            WHERE componente_padre_id IS NOT NULL
            SQL);

        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_componente_plantilla_id_foreign');
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_componente_id_posicion_campo_unique');
        DB::statement('ALTER TABLE definiciones_campo RENAME COLUMN componente_plantilla_id TO bloque_plantilla_id');
        DB::statement('ALTER TABLE definiciones_campo ADD COLUMN obligatorio BOOLEAN NOT NULL DEFAULT TRUE');
        DB::statement(
            'ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_bloque_plantilla_id_foreign
             FOREIGN KEY (bloque_plantilla_id) REFERENCES bloques_plantilla(id) ON DELETE CASCADE',
        );
        DB::statement(
            'ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_bloque_id_posicion_campo_unique
             UNIQUE (bloque_plantilla_id, posicion_definicion_campo)',
        );
        Schema::drop('componentes_plantilla');
    }

    private function restoreTemplateRoot(): void
    {
        Schema::table('plantillas_silabo', function (Blueprint $table): void {
            $table->string('nombre_plantilla_silabo', 180)->default('Plantilla institucional de sílabo');
            $table->text('descripcion_plantilla_silabo')->nullable();
            $table->boolean('plantilla_silabo_activa')->default(true);
        });
    }

    private function restoreCompletionPercentages(): void
    {
        Schema::table('silabos', function (Blueprint $table): void {
            $table->decimal('porcentaje_completitud', 5, 2)->default(0);
        });
        DB::statement('ALTER TABLE silabos ADD CONSTRAINT silabos_completitud_check CHECK (porcentaje_completitud BETWEEN 0 AND 100)');
        Schema::table('ejecuciones_validacion', function (Blueprint $table): void {
            $table->decimal('porcentaje_completitud', 5, 2)->default(0);
        });
    }

    private function createLegacyTemplateRevisionTriggers(): void
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

            CREATE TRIGGER tocar_revision_por_seccion
            AFTER INSERT OR UPDATE OR DELETE ON secciones_plantilla
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();
            CREATE TRIGGER tocar_revision_por_bloque
            AFTER INSERT OR UPDATE OR DELETE ON bloques_plantilla
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();
            CREATE TRIGGER tocar_revision_por_campo
            AFTER INSERT OR UPDATE OR DELETE ON definiciones_campo
            FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();

            CREATE OR REPLACE FUNCTION tocar_revision_configuracion_plantilla_directa()
            RETURNS trigger AS $$
            BEGIN
                IF ROW(OLD.nombre_plantilla_silabo, OLD.descripcion_plantilla_silabo,
                       OLD.plantilla_silabo_activa, OLD.mapeo_documento_plantilla)
                   IS DISTINCT FROM
                   ROW(NEW.nombre_plantilla_silabo, NEW.descripcion_plantilla_silabo,
                       NEW.plantilla_silabo_activa, NEW.mapeo_documento_plantilla) THEN
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
};
