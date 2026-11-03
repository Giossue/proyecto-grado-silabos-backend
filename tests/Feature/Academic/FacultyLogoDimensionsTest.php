<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\TestCase;

class FacultyLogoDimensionsTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        $admin = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($admin)->withSession(['active_role_assignment_id' => $admin->roleAssignments()->firstOrFail()->id]);
    }

    public function test_a_png_declaring_more_than_the_allowed_pixels_is_rejected_on_create_and_update(): void
    {
        $this->post(route('api.v1.admin.academic.store', 'facultad'), [
            'nombre' => 'Facultad Enorme', 'campus' => CampusLocation::Matriz->value, 'logo' => $this->declaredPng(4001, 10),
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('errors.logo', ['El logo no puede superar 4000 × 4000 píxeles.']);
        $this->assertDatabaseMissing('facultades', ['nombre_facultad' => 'Facultad Enorme']);

        $faculty = Faculty::query()->firstOrFail();
        $this->post(route('api.v1.admin.academic.update', ['facultad', $faculty->id]), [
            '_method' => 'PATCH', 'nombre' => $faculty->nombre, 'campus' => $faculty->campus->value, 'logo' => $this->declaredPng(10, 4001),
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('errors.logo', ['El logo no puede superar 4000 × 4000 píxeles.']);
    }

    public function test_a_logo_within_the_limit_is_still_accepted(): void
    {
        $this->post(route('api.v1.admin.academic.store', 'facultad'), [
            'nombre' => 'Facultad Normal', 'campus' => CampusLocation::Matriz->value, 'logo' => $this->transparentPng(4000, 120),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertDatabaseHas('facultades', ['nombre_facultad' => 'Facultad Normal']);
    }

    /**
     * PNG con solo la firma, la cabecera IHDR (RGBA) y el final: declara el tamaño sin traer píxeles.
     */
    private function declaredPng(int $width, int $height): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $content = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IEND', '');
        $path = (string) tempnam(sys_get_temp_dir(), 'logo-');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }
}
