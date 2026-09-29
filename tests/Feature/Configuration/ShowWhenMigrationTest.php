<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowWhenMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disability_details_depend_on_yes_and_migration_is_idempotent(): void
    {
        app(SyllabusTemplateProvisioner::class)->createWithOfficialFormat();
        FieldDefinition::query()->whereIn('clave', ['discapacidad_tipo', 'discapacidad_adaptacion'])->update(['mostrar_si' => null]);
        $custom = FieldDefinition::query()->where('clave', 'formacion_experiencia')->firstOrFail();
        $custom->update(['mostrar_si' => ['key' => 'discapacidad_tiene', 'value' => 'No']]);

        $migration = require database_path('migrations/2026_09_24_000142_mostrar_parte_segun_otra.php');
        $migration->up();
        $migration->up();

        foreach (['discapacidad_tipo', 'discapacidad_adaptacion'] as $key) {
            $this->assertSame(
                ['key' => 'discapacidad_tiene', 'value' => 'Sí'],
                FieldDefinition::query()->where('clave', $key)->firstOrFail()->mostrar_si,
            );
        }
        $this->assertNull(FieldDefinition::query()->where('clave', 'discapacidad_tiene')->firstOrFail()->mostrar_si);
        $this->assertSame(['key' => 'discapacidad_tiene', 'value' => 'No'], $custom->fresh()->mostrar_si);
    }
}
