<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Academic\Application\Queries\AcademicStructureViewData;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTransparentPng;
use Tests\TestCase;

/** I-34: logos del encabezado sin fondo, ajustados a la medida fija al guardar. */
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

    public function test_administrator_replaces_the_university_logo_and_it_is_served_publicly(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.logo.store'), ['logo' => $this->transparentPng(1012, 190)])
            ->assertSuccessful()
            ->assertSuccessful();

        Storage::disk('private')->assertExists('logos/institucion.png');
        $this->assertDatabaseHas('eventos_auditoria', ['accion_evento_auditoria' => 'institucion.logo_actualizado']);

        // Sin sesión: la imagen es pública.
        $this->get(route('logos.institution'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin');
    }

    public function test_the_university_logo_is_fitted_to_its_size_and_must_be_transparent(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.logo.store'), ['logo' => $this->opaquePng(1012, 190)])
            ->assertJsonValidationErrors('logo');
        Storage::disk('private')->assertMissing('logos/institucion.png');

        // Otra medida y otra proporción: se escala y se centra sobre lienzo transparente.
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.logo.store'), ['logo' => $this->transparentPng(1600, 400)])
            ->assertSuccessful()
            ->assertSuccessful();

        $this->assertStoredPngHasSize('logos/institucion.png', 1012, 190);
    }

    public function test_administrator_can_create_a_faculty_with_or_without_its_logo(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'facultad'), ['nombre' => 'Sin logo', 'campus' => CampusLocation::Matriz->value])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertDatabaseHas('facultades', [
            'nombre_facultad' => 'Sin logo',
            'logo_objeto_id' => null,
        ]);

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

        $faculties = collect(app(AcademicStructureViewData::class)->governance()['catalogs']['faculties'])
            ->keyBy('nombre');
        $this->assertNull($faculties->get('Sin logo')['logo_url']);
        $this->assertIsString($faculties->get('Con logo')['logo_url']);

        // Sin subida propia, la facultad muestra el logo de fábrica.
        $legacy = Faculty::query()->where('nombre', '!=', 'Con logo')->firstOrFail();
        $this->get(route('logos.faculty', $legacy))->assertOk();
    }

    public function test_coordinator_configures_only_the_faculty_of_the_active_career_from_the_dashboard(): void
    {
        $faculty = $this->coordinatorContext->career()->firstOrFail()->faculty()->firstOrFail();
        $this->assertNull($faculty->logo_objeto_id);

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.faculty-logo.store'), [
                'logo' => $this->transparentPng(320, 120),
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $faculty->refresh();
        $logo = $faculty->logoObject()->firstOrFail();
        $this->assertStringStartsWith("logos/facultades/{$faculty->id}/", $logo->ruta_interna);
        $this->assertStoredPngHasSize($logo->ruta_interna, 600, 180);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'facultad.logo_actualizado',
            'recurso_id' => $faculty->id,
            'asignacion_rol_id' => $this->coordinatorContext->id,
        ]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.coordination.faculty-logo.store'), [
                'logo' => $this->transparentPng(320, 120),
            ])
            ->assertClientError();
    }

    /** El archivo guardado tiene la medida fija y conserva el canal alfa (tipo de color 6). */
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
