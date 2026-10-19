<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secciones_plantilla', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plantilla_id')->constrained('plantilla_silabo')->cascadeOnDelete();
            $table->string('clave', 120);
            $table->string('titulo', 180);
            $table->text('descripcion')->nullable();
            $table->smallInteger('posicion');
            $table->unique(['plantilla_id', 'clave'], 'secciones_plantilla_clave_unica');
            $table->index(['plantilla_id', 'posicion']);
        });
        Schema::create('bloques_plantilla', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('seccion_plantilla_id')->constrained('secciones_plantilla')->cascadeOnDelete();
            $table->foreignUuid('plantilla_id')->constrained('plantilla_silabo')->cascadeOnDelete();
            $table->string('clave', 120);
            $table->string('titulo', 180)->default('');
            $table->smallInteger('posicion');
            $table->jsonb('configuracion');
            $table->unique(['plantilla_id', 'clave'], 'bloques_plantilla_clave_unica');
            $table->index(['seccion_plantilla_id', 'posicion']);
        });

        DB::transaction(function (): void {
            foreach (DB::table('plantilla_silabo')->get(['id', 'documento_plantilla']) as $template) {
                $document = json_decode((string) $template->documento_plantilla, true, 512, JSON_THROW_ON_ERROR);
                foreach (array_values($document['secciones'] ?? []) as $sectionIndex => $section) {
                    DB::table('secciones_plantilla')->insert([
                        'id' => $section['id'], 'plantilla_id' => $template->id,
                        'clave' => (string) $section['clave'], 'titulo' => (string) ($section['titulo'] ?? ''),
                        'descripcion' => is_string($section['descripcion'] ?? null) ? $section['descripcion'] : null,
                        'posicion' => (int) ($section['posicion'] ?? $sectionIndex + 1),
                    ]);
                    foreach (array_values($section['bloques'] ?? []) as $blockIndex => $block) {
                        DB::table('bloques_plantilla')->insert([
                            'id' => $block['id'], 'seccion_plantilla_id' => $section['id'], 'plantilla_id' => $template->id,
                            'clave' => (string) $block['clave'], 'titulo' => (string) ($block['titulo'] ?? ''),
                            'posicion' => (int) ($block['posicion'] ?? $blockIndex + 1),
                            'configuracion' => json_encode(is_array($block['configuracion'] ?? null) ? $block['configuracion'] : [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                }
            }

            $orphans = DB::table('definiciones_campo')->whereNotIn('bloque_plantilla_id', DB::table('bloques_plantilla')->select('id'));
            $referenced = (clone $orphans)->where(fn ($query) => $query
                ->whereExists(fn ($values) => $values->from('valores_campo')->whereColumn('valores_campo.definicion_campo_id', 'definiciones_campo.id'))
                ->orWhereExists(fn ($results) => $results->from('resultados_validacion')->whereColumn('resultados_validacion.definicion_campo_id', 'definiciones_campo.id')))
                ->count();
            if ($referenced > 0) {
                throw new RuntimeException("Hay {$referenced} preguntas sin parte con datos asociados; revíselas antes de migrar.");
            }
            $orphans->delete();

            DB::unprepared(<<<'SQL'
                ALTER TABLE definiciones_campo
                    ADD CONSTRAINT definiciones_campo_bloque_plantilla_id_foreign
                    FOREIGN KEY (bloque_plantilla_id) REFERENCES bloques_plantilla(id) ON DELETE CASCADE;

                DROP TRIGGER IF EXISTS validar_bloques_documento_plantilla ON plantilla_silabo;
                DROP FUNCTION IF EXISTS validar_bloques_documento_plantilla();

                CREATE OR REPLACE FUNCTION tocar_revision_configuracion_plantilla() RETURNS trigger AS $$
                DECLARE plantilla uuid;
                BEGIN
                    plantilla := CASE WHEN TG_OP = 'DELETE' THEN OLD.plantilla_id ELSE NEW.plantilla_id END;
                    UPDATE plantilla_silabo SET revision_configuracion = revision_configuracion + 1 WHERE id = plantilla;
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER tocar_revision_por_seccion AFTER INSERT OR UPDATE OR DELETE ON secciones_plantilla
                    FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();
                CREATE TRIGGER tocar_revision_por_bloque AFTER INSERT OR UPDATE OR DELETE ON bloques_plantilla
                    FOR EACH ROW EXECUTE FUNCTION tocar_revision_configuracion_plantilla();

                CREATE OR REPLACE FUNCTION tocar_revision_configuracion_plantilla_directa() RETURNS trigger AS $$
                BEGIN
                    IF OLD.mapeo_documento_plantilla IS DISTINCT FROM NEW.mapeo_documento_plantilla THEN
                        NEW.revision_configuracion := OLD.revision_configuracion + 1;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            Schema::table('plantilla_silabo', function (Blueprint $table): void {
                $table->dropColumn('documento_plantilla');
            });
        });
    }

    public function down(): void
    {
        throw new LogicException('La normalización de la plantilla (I-164) no se revierte: restaure el respaldo previo.');
    }
};
