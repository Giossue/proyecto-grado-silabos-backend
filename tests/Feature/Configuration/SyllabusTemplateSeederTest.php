<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyllabusTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_installs_the_initial_template_once_with_structured_text_fields(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->seed(SyllabusTemplateSeeder::class);
        $template = SyllabusTemplate::query()->firstOrFail();
        $sectionCount = $template->sections->count();
        $blockCount = $template->blocks()->count();
        $fieldCount = $template->fields()->count();

        $this->assertSame(12, $sectionCount);
        $this->assertGreaterThan(0, $blockCount);
        $this->assertGreaterThan(0, $fieldCount);
        $this->assertDatabaseMissing('definiciones_campo', ['tipo_definicion_campo' => 'markdown']);
        $this->assertNotNull($template->sections->firstWhere('clave', 'identificacion')?->blocks->first()?->configuracion['document'] ?? null);

        $this->seed(SyllabusTemplateSeeder::class);

        $this->assertSame(1, SyllabusTemplate::query()->count());
        $this->assertSame($sectionCount, SyllabusTemplate::query()->firstOrFail()->sections()->count());
        $this->assertSame($blockCount, SyllabusTemplate::query()->firstOrFail()->blocks()->count());
        $this->assertSame($fieldCount, SyllabusTemplate::query()->firstOrFail()->fields()->count());
    }

    public function test_it_formats_an_existing_empty_container_without_creating_another_template(): void
    {
        $this->seed(DatabaseSeeder::class);
        $template = app(SyllabusTemplateProvisioner::class)->createEmpty();

        $this->seed(SyllabusTemplateSeeder::class);

        $this->assertSame(1, SyllabusTemplate::query()->count());
        $this->assertCount(12, $template->fresh()->sections());
        $this->assertGreaterThan(0, $template->fresh()->fields()->count());
    }
}
