<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class SpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Mail::fake();
    }

    /** @param  list<list<string>>  $rows */
    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'datos.xlsx', null, null, true);
    }

    private function actingAsRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
    }

    public function test_admin_previews_then_imports_users_in_one_transaction(): void
    {
        $this->actingAsRole('admin@silabos.test');
        $career = Career::query()->where('activo', true)->firstOrFail();
        $file = fn () => $this->workbook([
            ['Nombre', 'Correo', 'Rol', 'Carrera'],
            ['Ana Uno', 'ana.uno@silabos.test', 'Docente', $career->nombre],
            ['Luis Dos', 'luis.dos@silabos.test', 'Bibliotecario', ''],
        ]);

        $this->postJson(route('api.v1.admin.users.import'), ['file' => $file()])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('created', 0);
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'ana.uno@silabos.test']);

        $this->postJson(route('api.v1.admin.users.import'), ['file' => $file(), 'confirm' => true])
            ->assertOk()->assertJsonPath('created', 2);
        $this->assertDatabaseHas('usuarios', ['correo_electronico' => 'ana.uno@silabos.test']);
        $this->assertDatabaseHas('usuarios', ['correo_electronico' => 'luis.dos@silabos.test']);
    }

    public function test_admin_import_reports_row_errors_and_creates_nothing(): void
    {
        $this->actingAsRole('admin@silabos.test');
        $file = $this->workbook([
            ['Nombre', 'Correo', 'Rol', 'Carrera'],
            ['Ana Uno', 'ana.uno@silabos.test', 'Docente', 'Carrera inexistente'],
            ['Luis Dos', 'luis.dos@silabos.test', 'Bibliotecario', ''],
        ]);

        $this->postJson(route('api.v1.admin.users.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('created', 0)
            ->assertJsonPath('rows.1.errors', []);
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'luis.dos@silabos.test']);
    }

    public function test_coordinator_imports_teachers_and_others_cannot(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $file = $this->workbook([['Nombre', 'Correo'], ['Doc Uno', 'doc.uno@silabos.test'], ['Doc Dos', 'doc.uno@silabos.test']]);
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('created', 0);

        $file = $this->workbook([['Nombre', 'Correo'], ['Doc Uno', 'doc.uno@silabos.test']]);
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('usuarios', ['correo_electronico' => 'doc.uno@silabos.test']);

        $this->getJson(route('api.v1.admin.users.import-template'))->assertForbidden();
        $this->actingAsRole('docente@silabos.test');
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $this->workbook([['Nombre', 'Correo'], ['X', 'x@silabos.test']])])->assertForbidden();
    }

    public function test_templates_download_as_xlsx(): void
    {
        $this->actingAsRole('admin@silabos.test');
        $this->get(route('api.v1.admin.users.import-template'))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_coordinator_imports_parallels_with_teacher_assignments(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $period = AcademicPeriod::query()->firstOrFail();
        $header = ['Código de materia', 'Paralelo', 'Jornada', 'Correo del docente'];
        $url = route('api.v1.coordination.academic.scheduled-import.store');

        $bad = $this->workbook([$header, ['NO-EXISTE', 'B', 'Matutina', ''], ['SW-601', 'C', 'Diurna', '']]);
        $this->postJson($url, ['file' => $bad, 'period_id' => $period->id, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('created', 0);

        $good = $this->workbook([$header, ['SW-601', 'B', 'Matutina', 'docente@silabos.test']]);
        $this->postJson($url, ['file' => $good, 'period_id' => $period->id, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('created', 1);
        $parallel = Parallel::query()->where('codigo', 'B')->firstOrFail();
        $this->assertSame('matutina', $parallel->jornada);
        $this->assertTrue(TeacherAssignment::query()->where('paralelo_id', $parallel->id)->where('activo', true)->exists());

        $again = $this->workbook([$header, ['SW-601', 'B', 'Matutina', 'docente@silabos.test']]);
        $this->postJson($url, ['file' => $again, 'period_id' => $period->id])
            ->assertOk()->assertJsonPath('valid', false);
    }
}
