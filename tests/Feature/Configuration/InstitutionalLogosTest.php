<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Academic\Application\Queries\AcademicStructureViewData;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTransparentPng;
use Tests\TestCase;

class InstitutionalLogosTest extends TestCase
{
    use MakesTransparentPng;
    use RefreshDatabase;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seed(DatabaseSeeder::class);
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
    }

    public function test_the_institution_logo_is_public_and_the_administrator_can_replace_it(): void
    {
        $this->get(route('logos.institution'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla'])->assertCreated();
        $template = SyllabusTemplate::query()->firstOrFail();

        $this->actingAsCoordinator()->postJson(route('api.v1.admin.template.logo.store', $template), ['logo' => $this->transparentPng(300, 100)])->assertForbidden();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.logo.store', $template), ['logo' => $this->opaquePng(300, 100)])->assertUnprocessable();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.logo.store', $template), ['logo' => $this->transparentPng(300, 100)])->assertOk();

        Storage::disk('private')->assertExists('logos/institucion.png');
    }

    public function test_administrator_must_create_a_faculty_with_a_transparent_logo(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'facultad'), ['nombre' => 'Sin logo', 'campus' => CampusLocation::Matriz->value])
            ->assertJsonValidationErrors('logo');
        $this->assertDatabaseMissing('facultades', ['nombre_facultad' => 'Sin logo']);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'facultad'), [
                'nombre' => 'Logo con fondo',
                'campus' => CampusLocation::Matriz->value,
                'logo' => $this->opaquePng(600, 180),
            ])
            ->assertJsonValidationErrors(['logo' => 'El logo debe ser un PNG sin fondo (con transparencia).']);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'facultad'), [
                'nombre' => 'Con logo',
                'campus' => CampusLocation::Laguacoto->value,
                'logo' => $this->transparentPng(300, 300),
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $faculty = Faculty::query()->where('nombre', 'Con logo')->firstOrFail();
        $logo = $faculty->logoObject()->firstOrFail();
        $this->assertStringStartsWith("logos/facultades/{$faculty->id}/", $logo->ruta_interna);
        $this->assertSame('logo_facultad', $logo->clasificacion);
        $this->assertStoredPngHasSize($logo->ruta_interna, 600, 180);
        $this->get(route('logos.faculty', $faculty))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin');

        $faculties = collect(app(AcademicStructureViewData::class)->governance()['catalogs']['faculties'])->keyBy('nombre');
        $this->assertIsString($faculties->get('Con logo')['logo_url']);

        $legacy = Faculty::query()->where('nombre', '!=', 'Con logo')->firstOrFail();
        $this->get(route('logos.faculty', $legacy))->assertOk();
    }

    public function test_only_an_administrator_can_replace_a_faculty_logo(): void
    {
        $faculty = $this->coordinatorContext->career()->firstOrFail()->faculty()->firstOrFail();
        $this->assertNull($faculty->logo_objeto_id);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.admin.academic.update', ['entity' => 'facultad', 'record' => $faculty]), [
                'nombre' => $faculty->nombre,
                'campus' => $faculty->campus->value,
                'logo' => $this->transparentPng(320, 120),
            ])
            ->assertForbidden();

        $this->postJson('/api/v1/coordination/faculty-logo', [
            'logo' => $this->transparentPng(320, 120),
        ])->assertNotFound();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['entity' => 'facultad', 'record' => $faculty]), [
                'nombre' => $faculty->nombre,
                'campus' => $faculty->campus->value,
            ])
            ->assertJsonValidationErrors('logo');

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['entity' => 'facultad', 'record' => $faculty]), [
                'nombre' => $faculty->nombre,
                'campus' => $faculty->campus->value,
                'logo' => $this->opaquePng(320, 120),
            ])
            ->assertJsonValidationErrors(['logo' => 'El logo debe ser un PNG sin fondo (con transparencia).']);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['entity' => 'facultad', 'record' => $faculty]), [
                'nombre' => $faculty->nombre,
                'campus' => $faculty->campus->value,
                'logo' => $this->transparentPng(320, 120),
            ])
            ->assertSuccessful();

        $faculty->refresh();
        $logo = $faculty->logoObject()->firstOrFail();
        $this->assertStringStartsWith("logos/facultades/{$faculty->id}/", $logo->ruta_interna);
        $this->assertStoredPngHasSize($logo->ruta_interna, 600, 180);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'academico.facultad.actualizacion',
            'recurso_id' => $faculty->id,
            'asignacion_rol_id' => $this->administratorContext->id,
        ]);
    }

    private function assertStoredPngHasSize(string $path, int $width, int $height): void
    {
        Storage::disk('private')->assertExists($path);
        $contents = Storage::disk('private')->get($path);
        $this->assertIsString($contents);
        $info = getimagesizefromstring($contents);
        $this->assertNotFalse($info);
        $this->assertSame([$width, $height], [$info[0], $info[1]]);
        $this->assertSame(6, ord($contents[25]));
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

        return $this;
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->coordinatorContext->id]);

        return $this;
    }
}
