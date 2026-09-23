<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TemplateJsonMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_reconstructs_and_restores_the_template_without_changing_ids(): void
    {
        $template = app(SyllabusTemplateProvisioner::class)->createWithOfficialFormat();
        $section = $template->sections()->firstOrFail();
        $block = $section->blocks()->firstOrFail();
        $field = $block->fields()->firstOrFail();
        $migration = require database_path('migrations/2026_09_23_000094_store_single_syllabus_template_as_json.php');

        $this->assertInstanceOf(Migration::class, $migration);
        $migration->down();

        $this->assertTrue(Schema::hasTable('plantillas_silabo'));
        $this->assertTrue(Schema::hasTable('componentes_plantilla'));
        $this->assertDatabaseHas('componentes_plantilla', [
            'id' => $section->id,
            'componente_padre_id' => null,
        ]);
        $this->assertDatabaseHas('componentes_plantilla', [
            'id' => $block->id,
            'componente_padre_id' => $section->id,
        ]);
        $this->assertDatabaseHas('definiciones_campo', [
            'id' => $field->id,
            'componente_plantilla_id' => $block->id,
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasTable('plantilla_silabo'));
        $this->assertFalse(Schema::hasTable('componentes_plantilla'));
        $document = DB::table('plantilla_silabo')->where('id', $template->id)->value('documento_plantilla');
        $document = is_string($document) ? json_decode($document, true, 512, JSON_THROW_ON_ERROR) : $document;
        $this->assertSame($section->id, $document['secciones'][0]['id']);
        $this->assertSame($block->id, $document['secciones'][0]['bloques'][0]['id']);
        $this->assertDatabaseHas('definiciones_campo', [
            'id' => $field->id,
            'bloque_plantilla_id' => $block->id,
        ]);
    }
}
