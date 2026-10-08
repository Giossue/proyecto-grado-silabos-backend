<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectRequirement;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
            ['Primer nombre', 'Segundo nombre', 'Primer apellido', 'Segundo apellido', 'Correo', 'Rol', 'Carrera'],
            ['Ana', '', 'Uno', '', 'ana.uno@silabos.test', 'Docente', $career->nombre],
            ['Luis', 'Pedro', 'Dos', 'Tres', 'luis.dos@silabos.test', 'Bibliotecario', ''],
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
            ['Primer nombre', 'Segundo nombre', 'Primer apellido', 'Segundo apellido', 'Correo', 'Rol', 'Carrera'],
            ['Ana', '', 'Uno', '', 'ana.uno@silabos.test', 'Docente', 'Carrera inexistente'],
            ['Luis', 'Pedro', 'Dos', 'Tres', 'luis.dos@silabos.test', 'Bibliotecario', ''],
        ]);

        $this->postJson(route('api.v1.admin.users.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('created', 0)
            ->assertJsonPath('rows.1.errors', []);
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'luis.dos@silabos.test']);
    }

    public function test_coordinator_imports_teachers_and_others_cannot(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $file = $this->workbook([['Primer nombre', 'Segundo nombre', 'Primer apellido', 'Segundo apellido', 'Correo'], ['Doc', '', 'Uno', '', 'doc.uno@silabos.test'], ['Doc', '', 'Dos', '', 'doc.uno@silabos.test']]);
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('created', 0);

        $file = $this->workbook([['Primer nombre', 'Segundo nombre', 'Primer apellido', 'Segundo apellido', 'Correo'], ['Doc', '', 'Uno', '', 'doc.uno@silabos.test']]);
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('usuarios', ['correo_electronico' => 'doc.uno@silabos.test']);

        $this->getJson(route('api.v1.admin.users.import-template'))->assertForbidden();
        $this->actingAsRole('docente@silabos.test');
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $this->workbook([['Primer nombre', 'Segundo nombre', 'Primer apellido', 'Segundo apellido', 'Correo'], ['X', '', 'Y', '', 'x@silabos.test']])])->assertForbidden();
    }

    public function test_coordinator_imports_the_curriculum_with_its_requirements_all_or_nothing(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $header = ['Código', 'Nombre de la materia', 'Ciclo', 'Unidad de organización', 'Modalidad', 'Horas ACD', 'Horas APE', 'Horas AA', 'Créditos', 'Prerrequisitos (códigos)', 'Correquisitos (códigos)'];
        $bad = $this->workbook([$header, ['IMP-001', 'Programación I', '1', 'Unidad básica', 'Presencial', '48', '32', '64', '3', 'NO-EXISTE', '']]);
        $this->postJson(route('api.v1.coordination.academic.curriculum-import.store'), ['file' => $bad, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('created', 0);

        $file = $this->workbook([
            $header,
            ['IMP-001', 'Programación I', '1', 'Unidad básica', 'Presencial', '48', '32', '64', '3', '', ''],
            ['IMP-002', 'Programación II', '2', 'Unidad profesional', '', '48', '32', '64', '3', 'IMP-001', ''],
        ]);
        $this->postJson(route('api.v1.coordination.academic.curriculum-import.store'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('created', 2);
        $this->assertSame(144, Subject::query()->where('codigo_asignatura', 'IMP-002')->firstOrFail()->horas_totales);
        $this->assertSame(1, SubjectRequirement::query()
            ->whereHas('subject', fn ($query) => $query->where('codigo_asignatura', 'IMP-002'))->count());

        $this->getJson(route('api.v1.coordination.academic.curriculum-import.template'))->assertOk();
        $this->actingAsRole('docente@silabos.test');
        $this->getJson(route('api.v1.coordination.academic.curriculum-import.template'))->assertForbidden();
    }

    public function test_requirement_types_follow_the_cycles(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $header = ['Código', 'Nombre de la materia', 'Ciclo', 'Unidad de organización', 'Modalidad', 'Horas ACD', 'Horas APE', 'Horas AA', 'Créditos', 'Prerrequisitos (códigos)', 'Correquisitos (códigos)'];
        $wrong = $this->workbook([$header, ['CIC-001', 'Base', '1', 'Unidad básica', '', '1', '1', '1', '1', '', ''], ['CIC-002', 'Par', '1', 'Unidad básica', '', '1', '1', '1', '1', 'CIC-001', '']]);
        $this->postJson(route('api.v1.coordination.academic.curriculum-import.store'), ['file' => $wrong])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('rows.1.errors.0', 'El prerrequisito CIC-001 debe estar en un ciclo anterior.');

        $file = $this->workbook([$header, ['CIC-001', 'Base', '1', 'Unidad básica', '', '1', '1', '1', '1', '', ''], ['CIC-002', 'Siguiente', '2', 'Unidad básica', '', '1', '1', '1', '1', '', '']]);
        $this->postJson(route('api.v1.coordination.academic.curriculum-import.store'), ['file' => $file, 'confirm' => true])->assertJsonPath('created', 2);
        $subjects = Subject::query()->whereIn('codigo_asignatura', ['CIC-001', 'CIC-002'])->get()->keyBy('codigo_asignatura');
        $career = $subjects['CIC-001']->carrera_id;
        $this->postJson(route('api.v1.coordination.academic.curriculum.requirements.store', $career), [
            'subject_id' => $subjects['CIC-002']->id, 'requirement_id' => $subjects['CIC-001']->id, 'type' => 'correquisito',
        ])->assertJsonValidationErrors('requirement_id');
        $this->postJson(route('api.v1.coordination.academic.curriculum.requirements.store', $career), [
            'subject_id' => $subjects['CIC-002']->id, 'requirement_id' => $subjects['CIC-001']->id, 'type' => 'prerrequisito',
        ])->assertSuccessful()->assertJsonPath('message', 'Prerrequisito agregado.');
    }

    public function test_templates_download_as_xlsx(): void
    {
        $this->actingAsRole('admin@silabos.test');
        $this->get(route('api.v1.admin.users.import-template'))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $book = IOFactory::load($this->templateFile(route('api.v1.admin.users.import-template')));
        $sheet = $book->getSheetByName('Datos');
        $this->assertSame('Segundo apellido', $sheet->getCell('D1')->getValue());
        $this->assertSame('Rol', $sheet->getCell('F1')->getValue());
        $this->assertSame('list', $sheet->getCell('F2')->getDataValidation()->getType());
        $this->assertSame('list', $sheet->getCell('G2')->getDataValidation()->getType());
        $this->assertSame('Indicaciones', $sheet->getCell('I1')->getValue());
        $this->assertStringContainsString('Primer nombre', (string) $sheet->getCell('I2')->getValue());
    }

    public function test_coordinator_teacher_template_downloads_with_names(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $teachers = IOFactory::load($this->templateFile(route('api.v1.coordination.academic.teachers.import-template')))->getSheetByName('Datos');
        $this->assertSame('Primer apellido', $teachers->getCell('C1')->getValue());
    }

    public function test_admin_imports_careers_all_or_nothing(): void
    {
        $this->actingAsRole('admin@silabos.test');
        $faculty = Faculty::query()->where('activo', true)->firstOrFail();
        $header = ['Facultad', 'Modalidad', 'Código de carrera', 'Nombre de la carrera', 'Número de ciclos'];
        $url = route('api.v1.admin.academic.careers.import');

        $bad = $this->workbook([$header, [$faculty->nombre, 'Presencial', 'NC-1', 'Carrera Uno', '10'], ['Inexistente', 'Virtual', 'NC-2', 'Carrera Dos', '0']]);
        $this->postJson($url, ['file' => $bad, 'confirm' => true])->assertOk()->assertJsonPath('valid', false)->assertJsonPath('created', 0);
        $this->assertDatabaseMissing('carreras', ['codigo_carrera' => 'NC-1']);

        $good = $this->workbook([$header, [$faculty->nombre, 'Presencial', 'NC-1', 'Carrera Uno', '10']]);
        $this->postJson($url, ['file' => $good, 'confirm' => true])->assertOk()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('carreras', ['codigo_carrera' => 'NC-1']);

        $sheet = IOFactory::load($this->templateFile(route('api.v1.admin.academic.careers.import-template')))->getSheetByName('Datos');
        $this->assertSame('list', $sheet->getCell('A2')->getDataValidation()->getType());
        $this->assertSame('list', $sheet->getCell('B2')->getDataValidation()->getType());
    }

    private function templateFile(string $url): string
    {
        ob_start();
        $this->get($url)->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, (string) ob_get_clean());

        return $path;
    }
}
