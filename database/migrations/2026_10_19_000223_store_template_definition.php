<?php

use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Domain\TemplateStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE plantilla_silabo ADD COLUMN definicion_plantilla json NOT NULL DEFAULT '{}'::json");
        DB::statement("ALTER TABLE plantilla_silabo ADD CONSTRAINT definicion_plantilla_objeto CHECK (json_typeof(definicion_plantilla) = 'object')");

        $official = OfficialTemplate::definition();
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'mapeo_documento_plantilla']) as $template) {
            $mapping = json_decode((string) ($template->mapeo_documento_plantilla ?? 'null'), true);
            $definition = [...$official->toArray(), 'document' => TemplateStyle::fromLegacyMapping($official->document(), is_array($mapping) ? $mapping : null)];
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)->update([
                'definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS marcar_mapeo_plantilla_modificado ON plantilla_silabo;
            DROP FUNCTION IF EXISTS marcar_mapeo_plantilla_modificado();
            ALTER TABLE plantilla_silabo DROP COLUMN mapeo_documento_plantilla;

            CREATE OR REPLACE FUNCTION marcar_definicion_plantilla_modificada() RETURNS trigger AS $$
            BEGIN
                IF OLD.definicion_plantilla::text IS DISTINCT FROM NEW.definicion_plantilla::text THEN
                    NEW.plantilla_modificada_en := clock_timestamp();
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER marcar_definicion_plantilla_modificada
                BEFORE UPDATE OF definicion_plantilla ON plantilla_silabo
                FOR EACH ROW EXECUTE FUNCTION marcar_definicion_plantilla_modificada();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-229.');
    }
};
