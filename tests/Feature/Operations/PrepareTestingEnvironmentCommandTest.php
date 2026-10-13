<?php

namespace Tests\Feature\Operations;

use App\Console\Commands\PrepareTestingEnvironmentCommand;
use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTransparentPng;
use Tests\TestCase;

class PrepareTestingEnvironmentCommandTest extends TestCase
{
    use MakesTransparentPng;
    use RefreshDatabase;

    private const PASSWORD = 'Prueba-Local-2026!';

    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seed(DatabaseSeeder::class);
        $this->career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();
        app(InstitutionalLogos::class)->storeFaculty($this->career->faculty, $this->transparentPng(600, 180));
        foreach ([['SW-801', 'Calidad de Software', 8], ['SW-101', 'Cálculo I', 1]] as [$code, $name, $cycle]) {
            Subject::query()->create([
                'carrera_id' => $this->career->id, 'codigo_asignatura' => $code, 'nombre' => $name,
                'ciclo' => $cycle, 'creditos' => 4, 'horas_ac' => 64, 'horas_pae' => 32, 'horas_aa' => 96, 'activo' => true,
            ]);
        }
        $admin = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($admin)->withSession(['active_role_assignment_id' => $admin->roleAssignments()->value('id')]);
        $this->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla']);
        $this->postJson(route('api.v1.admin.template.format', SyllabusTemplate::query()->firstOrFail()))->assertSuccessful();
        $this->fillSubjectProfiles();
        putenv('PRUEBAS_CONTRASENA='.self::PASSWORD);
    }

    protected function tearDown(): void
    {
        putenv('PRUEBAS_CONTRASENA');
        parent::tearDown();
    }

    public function test_it_prepares_teachers_and_opens_without_a_source_catalog(): void
    {
        $this->artisan('pruebas:preparar', ['--carrera' => 'SOFTWARE'])
            ->expectsOutputToContain('Convocatoria de carrera abierta.')
            ->assertSuccessful();

        foreach (PrepareTestingEnvironmentCommand::TEACHERS as $teacher) {
            $user = User::query()->where('correo_electronico', $teacher['email'])->firstOrFail();
            $this->assertTrue($user->activo && $user->hasVerifiedEmail());
            $this->assertTrue(Hash::check(self::PASSWORD, $user->contrasena));
            $this->assertTrue(RoleAssignment::query()->effective()->where('usuario_id', $user->id)
                ->where('rol', 'docente')->where('carrera_id', $this->career->id)->exists());
        }
        $this->assertSame(
            ['Arquitectura de Software', 'Calidad de Software', 'Cálculo I'],
            Parallel::query()->with('subject')->get()->pluck('subject.nombre')->unique()->sort()->values()->all(),
        );
        $this->assertSame(SyllabusProcess::STATE_OPEN, SyllabusProcess::query()->value('estado'));
        $this->assertSame(1, Convocation::query()->count());
        $this->assertFalse(User::query()->where('correo_electronico', PrepareTestingEnvironmentCommand::COORDINATOR_EMAIL)->exists());
    }

    public function test_rerun_without_sources_is_idempotent(): void
    {
        $this->artisan('pruebas:preparar', ['--carrera' => 'SOFTWARE'])->assertSuccessful();

        $this->artisan('pruebas:preparar', ['--carrera' => 'SOFTWARE'])->assertSuccessful();
        $this->artisan('pruebas:preparar', ['--carrera' => 'SOFTWARE'])->assertSuccessful();

        $this->assertSame('abierta', Convocation::query()->value('estado'));
        $this->assertSame(3, Syllabus::query()->count());
        $byTeacher = Syllabus::query()->with(['parallel.subject', 'parallel.teacherAssignments.roleAssignment.user'])->get()
            ->mapWithKeys(fn (Syllabus $s): array => [$s->parallel->teacherAssignments->first()->roleAssignment->user->correo_electronico => $s->subject->nombre]);
        $this->assertSame('Calidad de Software', $byTeacher['docente1.pruebas@ueb.edu.ec']);
        $this->assertSame('Cálculo I', $byTeacher['docente2.pruebas@ueb.edu.ec']);
        $this->assertSame(1, SyllabusProcess::query()->count());
    }

    public function test_it_rejects_a_weak_password(): void
    {
        putenv('PRUEBAS_CONTRASENA=corta');

        $this->artisan('pruebas:preparar', ['--carrera' => 'SOFTWARE'])->assertFailed();
        $this->assertFalse(User::query()->where('correo_electronico', 'docente1.pruebas@ueb.edu.ec')->exists());
    }
}
