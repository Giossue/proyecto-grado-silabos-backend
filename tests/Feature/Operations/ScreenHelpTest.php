<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\ScreenHelp;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenHelpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_every_screen_ships_with_a_valid_help_text(): void
    {
        $screens = ScreenHelp::query()->pluck('pantalla')->all();

        $this->assertGreaterThanOrEqual(35, count($screens));
        foreach (['/admin/panel', '/coordinacion/malla', '/docente/mis-silabos', '/biblioteca/revisiones'] as $screen) {
            $this->assertContains($screen, $screens);
        }
        $this->assertSame(0, ScreenHelp::query()->where('activa', false)->count());
        foreach (ScreenHelp::query()->get() as $help) {
            $this->assertMatchesRegularExpression('/^\/[a-z0-9\/:_-]*$/', $help->pantalla);
            $this->assertLessThanOrEqual(4000, mb_strlen($help->contenido_ayuda));
        }
    }

    public function test_any_role_reads_only_the_active_help_of_its_screen(): void
    {
        ScreenHelp::query()->delete();
        ScreenHelp::query()->create(['pantalla' => '/docente/mis-silabos', 'titulo_ayuda' => 'Mis sílabos', 'contenido_ayuda' => 'Texto', 'activa' => true, 'editada_en_ayuda' => now()]);
        ScreenHelp::query()->create(['pantalla' => '/docente/panel', 'titulo_ayuda' => 'Panel', 'contenido_ayuda' => 'Texto', 'activa' => false, 'editada_en_ayuda' => now()]);

        $this->actingAsRole('docente@silabos.test')
            ->getJson(route('api.v1.help.show', ['screen' => '/docente/mis-silabos']))
            ->assertOk()->assertJsonPath('help.title', 'Mis sílabos');
        $this->getJson(route('api.v1.help.show', ['screen' => '/docente/panel']))->assertOk()->assertJsonPath('help', null);
        $this->getJson(route('api.v1.help.show', ['screen' => '/otra']))->assertOk()->assertJsonPath('help', null);
        $this->getJson(route('api.v1.help.show', ['screen' => 'sin-barra']))->assertUnprocessable();
    }

    public function test_only_administration_manages_help(): void
    {
        ScreenHelp::query()->delete();
        $payload = ['screen' => '/admin/usuarios', 'title' => 'Usuarios', 'content' => 'Texto', 'active' => true];

        $this->actingAsRole('docente@silabos.test')
            ->putJson(route('api.v1.admin.help.update'), $payload)->assertForbidden();
        $this->getJson(route('api.v1.admin.help.index'))->assertForbidden();
        $this->deleteJson(route('api.v1.admin.help.destroy'), ['screen' => '/admin/usuarios'])->assertForbidden();
        $this->assertDatabaseCount('ayudas_pantalla', 0);
    }

    public function test_administration_saves_replaces_and_deletes_help_with_audit(): void
    {
        ScreenHelp::query()->delete();
        $payload = ['screen' => '/admin/usuarios', 'title' => 'Usuarios', 'content' => "Uno\n\nDos", 'active' => true];

        $this->actingAsRole('admin@silabos.test')
            ->putJson(route('api.v1.admin.help.update'), $payload)->assertOk()->assertJsonPath('help.screen', '/admin/usuarios');
        $this->putJson(route('api.v1.admin.help.update'), [...$payload, 'title' => 'Cuentas', 'active' => false])->assertOk();

        $this->assertDatabaseCount('ayudas_pantalla', 1);
        $this->getJson(route('api.v1.admin.help.index'))->assertOk()
            ->assertJsonPath('help.0.title', 'Cuentas')->assertJsonPath('help.0.active', false);

        $this->deleteJson(route('api.v1.admin.help.destroy'), ['screen' => '/admin/usuarios'])->assertNoContent();
        $this->assertDatabaseCount('ayudas_pantalla', 0);
        $this->assertSame(
            ['ayuda.eliminada', 'ayuda.guardada', 'ayuda.guardada'],
            AuditEvent::query()->where('tipo_recurso', 'ayuda_pantalla')->orderBy('accion')->pluck('accion')->all(),
        );
    }

    public function test_help_validates_the_key_and_the_text(): void
    {
        $this->actingAsRole('admin@silabos.test')
            ->putJson(route('api.v1.admin.help.update'), ['screen' => 'admin/usuarios', 'title' => '', 'content' => '', 'active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['screen', 'title', 'content']);
    }

    private function actingAsRole(string $email): static
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);

        return $this;
    }
}
