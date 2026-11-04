<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Operations\Application\ScreenHelpCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScreenHelpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_help_texts_live_in_code_and_not_in_the_database(): void
    {
        $screens = array_keys(ScreenHelpCatalog::HELP);

        $this->assertFalse(Schema::hasTable('ayudas_pantalla'));
        $this->assertGreaterThanOrEqual(30, count($screens));
        foreach (['/admin/convocatorias', '/admin/configuracion/sistema', '/docente/mis-silabos/:syllabus', '/coordinacion/revisiones/:revision/historial', '/docente/configuracion/notificaciones', '/coordinacion/malla', '/coordinacion/revisiones', '/docente/mis-silabos', '/biblioteca/revisiones', '/biblioteca/historial'] as $screen) {
            $this->assertContains($screen, $screens);
        }
        foreach (['/admin/ayuda', '/docente/panel', '/biblioteca/panel', '/coordinacion/panel'] as $screen) {
            $this->assertNotContains($screen, $screens);
        }
        $this->assertStringStartsWith('Arriba ve el aviso con el plazo de entrega', ScreenHelpCatalog::HELP['/coordinacion/revisiones']['content']);
        $this->assertStringStartsWith('Arriba ve el aviso con el plazo de la convocatoria', ScreenHelpCatalog::HELP['/docente/mis-silabos']['content']);
        $this->assertStringStartsWith('Aquí están sus avisos del período actual', ScreenHelpCatalog::HELP['/docente/notificaciones']['content']);
        $this->assertStringContainsString('Al pulsar una fila se abren sus opciones: pausar, reanudar, prorrogar el plazo, finalizar o eliminar', ScreenHelpCatalog::HELP['/admin/convocatorias']['content']);
        $this->assertStringContainsString('2 veces como máximo, y nunca después del último día de clases', ScreenHelpCatalog::HELP['/admin/convocatorias']['content']);
        $this->assertStringContainsString('Si no la finaliza, el sistema lo hace solo al terminar el último día de entrega', ScreenHelpCatalog::HELP['/admin/convocatorias']['content']);
        $this->assertStringContainsString('Una convocatoria finalizada se vuelve a abrir con «Prorrogar plazo»', ScreenHelpCatalog::HELP['/admin/convocatorias']['content']);
        $this->assertStringNotContainsString('no se deshace', ScreenHelpCatalog::HELP['/admin/convocatorias']['content']);
        foreach (ScreenHelpCatalog::HELP as $screen => $help) {
            $this->assertMatchesRegularExpression('/^\/[a-z0-9\/:_-]*$/', $screen);
            $this->assertNotSame('', trim($help['title']));
            $this->assertLessThanOrEqual(4000, mb_strlen($help['content']));
        }
    }

    public function test_any_role_reads_the_help_of_a_screen(): void
    {
        $this->actingAsRole('docente@silabos.test')
            ->getJson(route('api.v1.help.show', ['screen' => '/docente/mis-silabos']))
            ->assertOk()
            ->assertExactJson(['help' => ['screen' => '/docente/mis-silabos', ...ScreenHelpCatalog::HELP['/docente/mis-silabos']]]);
        $this->getJson(route('api.v1.help.show', ['screen' => '/docente/panel']))->assertOk()->assertJsonPath('help', null);
        $this->getJson(route('api.v1.help.show', ['screen' => 'sin-barra']))->assertUnprocessable();
    }

    public function test_the_active_role_receives_only_the_help_of_its_screens(): void
    {
        $expected = array_values(array_filter(array_keys(ScreenHelpCatalog::HELP), fn (string $screen): bool => str_starts_with($screen, '/docente/')));

        $response = $this->actingAsRole('docente@silabos.test')->getJson(route('api.v1.help.available'))->assertOk();

        $this->assertSame($expected, array_column($response->json('help'), 'screen'));
    }

    public function test_the_help_can_no_longer_be_edited(): void
    {
        $this->actingAsRole('admin@silabos.test');

        $this->getJson('/api/v1/admin/help')->assertNotFound();
        $this->putJson('/api/v1/admin/help', ['screen' => '/admin/usuarios', 'title' => 'T', 'content' => 'C', 'active' => true])->assertNotFound();
        $this->deleteJson('/api/v1/admin/help', ['screen' => '/admin/usuarios'])->assertNotFound();
    }

    private function actingAsRole(string $email): static
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);

        return $this;
    }
}
