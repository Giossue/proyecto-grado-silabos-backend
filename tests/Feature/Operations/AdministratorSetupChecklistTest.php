<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdministratorSetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE_HINT = 'Secciones y campos con un responsable: Docente, Coordinación o Institucional. El mismo editor permite texto, listas y tablas.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_template_step_is_done_once_the_template_can_be_used(): void
    {
        $this->templateStep()->assertJsonPath('done', false)->assertJsonPath('hint', self::TEMPLATE_HINT);

        app(SyllabusTemplateProvisioner::class)->createWithOfficialFormat();

        $this->templateStep()->assertJsonPath('done', true)->assertJsonPath('hint', self::TEMPLATE_HINT);
    }

    public function test_a_template_with_content_that_cannot_be_used_keeps_the_step_pending_and_says_what_is_missing(): void
    {
        $template = app(SyllabusTemplateProvisioner::class)->createWithOfficialFormat();
        $required = $template->definition()->requiredSections()[0];
        $template->removeSectionDocument((string) $template->sections->firstWhere('clave', $required)?->id);
        $this->assertTrue(SyllabusTemplate::query()->firstOrFail()->hasStructure());

        $this->templateStep()
            ->assertJsonPath('done', false)
            ->assertJsonPath('hint', "La plantilla no tiene las áreas obligatorias: {$required}.");
        $this->dashboard()->assertJsonPath('setup.done', 4);
    }

    public function test_without_careers_the_coordinator_step_does_not_claim_an_active_coordination(): void
    {
        Career::query()->update(['activo' => false]);

        $step = collect($this->dashboard()->json('setup.steps'))->firstWhere('key', 'coordinators');

        $this->assertIsArray($step);
        $this->assertFalse($step['done']);
        $this->assertSame('Primero registre las carreras.', $step['hint']);
    }

    public function test_the_institutional_convocation_can_be_opened_only_once_every_previous_step_is_done(): void
    {
        $processStep = fn (): array => collect($this->dashboard()->json('setup.steps'))->firstWhere('key', 'process');
        $this->assertNull($processStep()['action']);

        app(SyllabusTemplateProvisioner::class)->createWithOfficialFormat();

        $this->assertSame('open_process', $processStep()['action']);
    }

    private function templateStep(): TestResponse
    {
        $steps = $this->dashboard()->json('setup.steps');
        $step = collect(is_array($steps) ? $steps : [])->firstWhere('key', 'template');
        $this->assertIsArray($step);

        return TestResponse::fromBaseResponse(response()->json($step));
    }

    private function dashboard(): TestResponse
    {
        $administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();

        return $this->actingAs($administrator)
            ->withSession(['active_role_assignment_id' => $administrator->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk();
    }
}
