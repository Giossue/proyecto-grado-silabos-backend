<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectRequirement;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetReader;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use Tests\TestCase;
use ZipArchive;

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
            ['Nombres', 'Apellidos', 'Correo', 'Rol', 'Carrera'],
            ['Ana Luz', 'Uno Cinco', 'ana.uno@silabos.test', 'Docente', $career->nombre],
            ['Luis Pedro', 'Dos Tres', 'luis.dos@silabos.test', 'Bibliotecario', ''],
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
            ['Nombres', 'Apellidos', 'Correo', 'Rol', 'Carrera'],
            ['Ana', 'Uno', 'ana.uno@silabos.test', 'Docente', 'Carrera inexistente'],
            ['Luis Pedro', 'Dos Tres', 'luis.dos@silabos.test', 'Bibliotecario', ''],
            ['Eva Pedro', '', 'eva.dos@silabos.test', 'Bibliotecario', ''],
        ]);

        $this->postJson(route('api.v1.admin.users.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('created', 0)
            ->assertJsonPath('rows.1.errors', [])
            ->assertJsonPath('rows.2.errors', ['Los apellidos son obligatorios.']);
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'luis.dos@silabos.test']);
    }

    public function test_coordinator_imports_teachers_and_others_cannot(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $file = $this->workbook([['Nombres', 'Apellidos', 'Correo'], ['Doc Ana', 'Uno Mía', 'doc.uno@silabos.test'], ['Doc Ana', 'Dos Mía', 'doc.uno@silabos.test']]);
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('created', 0);

        $file = $this->workbook([['Nombres', 'Apellidos', 'Correo'], ['Doc Ana', 'Uno Mía', 'doc.uno@silabos.test']]);
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('usuarios', ['correo_electronico' => 'doc.uno@silabos.test']);

        $this->getJson(route('api.v1.admin.users.import-template'))->assertForbidden();
        $this->actingAsRole('docente@silabos.test');
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $this->workbook([['Nombres', 'Apellidos', 'Correo'], ['X', 'Y', 'x@silabos.test']])])->assertForbidden();
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

    public function test_curriculum_imports_into_a_career_without_subjects(): void
    {
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $career = Career::query()->create([
            'facultad_id' => Faculty::query()->firstOrFail()->id,
            'codigo_carrera' => 'CARR-VACIA',
            'nombre' => 'Carrera sin malla',
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);
        $role = RoleAssignment::query()->create(['usuario_id' => $coordinator->id, 'rol' => 'coordinador', 'carrera_id' => $career->id, 'activo' => true]);
        $this->actingAs($coordinator)->withSession(['active_role_assignment_id' => $role->id]);
        $header = ['Código', 'Nombre de la materia', 'Ciclo', 'Unidad de organización', 'Modalidad', 'Horas ACD', 'Horas APE', 'Horas AA', 'Créditos', 'Prerrequisitos (códigos)', 'Correquisitos (códigos)'];
        $file = $this->workbook([$header, ['VAC-001', 'Base', '1', 'Unidad básica', '', '1', '1', '1', '1', '', ''], ['VAC-002', 'Siguiente', '2', 'Unidad básica', '', '1', '1', '1', '1', 'VAC-001', '']]);

        $this->postJson(route('api.v1.coordination.academic.curriculum-import.store'), ['file' => $file, 'confirm' => true])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('created', 2);
        $this->assertSame(2, Subject::query()->where('carrera_id', $career->id)->count());
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
        $this->assertSame('Nombres', $sheet->getCell('A1')->getValue());
        $this->assertSame('Apellidos', $sheet->getCell('B1')->getValue());
        $this->assertSame('Rol', $sheet->getCell('D1')->getValue());
        $this->assertSame('list', $sheet->getCell('D2')->getDataValidation()->getType());
        $this->assertSame('list', $sheet->getCell('E2')->getDataValidation()->getType());
        $this->assertSame('Indicaciones', $sheet->getCell('G1')->getValue());
        $this->assertStringContainsString('Nombres', (string) $sheet->getCell('G2')->getValue());
    }

    public function test_teacher_import_requires_names_and_surnames_written_with_letters(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $file = $this->workbook([
            ['Nombres', 'Apellidos', 'Correo'],
            ['', 'Uno Dos', 'sin.nombres@silabos.test'],
            ['Ana', '', 'sin.apellidos@silabos.test'],
            ['Ana2', 'Uno', 'con.numero@silabos.test'],
            ['María José', "De la Cruz-O'Neil", 'compuesto@silabos.test'],
            ['Ana', 'Uno', 'un.nombre.un.apellido@silabos.test'],
        ]);

        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $file, 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('rows.0.errors', ['Los nombres son obligatorios.'])
            ->assertJsonPath('rows.1.errors', ['Los apellidos son obligatorios.'])
            ->assertJsonPath('rows.2.errors', ['Los nombres solo admiten letras, espacios, apóstrofo y guion.'])
            ->assertJsonPath('rows.3.errors', [])
            ->assertJsonPath('rows.4.errors', []);
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'compuesto@silabos.test']);
    }

    public function test_coordinator_teacher_template_downloads_with_names(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $teachers = IOFactory::load($this->templateFile(route('api.v1.coordination.academic.teachers.import-template')))->getSheetByName('Datos');
        $this->assertSame('Apellidos', $teachers->getCell('B1')->getValue());
        $this->assertStringContainsString('Todas las columnas son obligatorias', (string) $teachers->getCell('E2')->getValue());
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

    public function test_every_template_locks_all_but_the_data_rows(): void
    {
        $templates = [
            'admin@silabos.test' => [route('api.v1.admin.users.import-template'), 5],
            'coordinador@silabos.test' => [route('api.v1.coordination.academic.teachers.import-template'), 3],
        ];
        foreach ([
            'admin@silabos.test' => [route('api.v1.admin.academic.careers.import-template'), 5],
            'coordinador@silabos.test' => [route('api.v1.coordination.academic.curriculum-import.template'), 12],
        ] as $email => $template) {
            $templates[$email.'#'.$template[0]] = $template;
        }

        foreach ($templates as $key => [$url, $columns]) {
            $this->actingAsRole(explode('#', $key)[0]);
            $book = IOFactory::load($this->templateFile($url));
            $sheet = $book->getSheetByName('Datos');
            $last = Coordinate::stringFromColumnIndex($columns);
            $notes = Coordinate::stringFromColumnIndex($columns + 2);

            $this->assertTrue($sheet->getProtection()->getSheet(), "{$url}: la hoja de datos debe estar protegida");
            $this->assertTrue($book->getSheetByName('Listas')->getProtection()->getSheet(), "{$url}: la hoja de listas debe estar protegida");
            $this->assertNotSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('A1')->getProtection()->getLocked(), "{$url}: encabezado bloqueado");
            $this->assertNotSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle("{$notes}2")->getProtection()->getLocked(), "{$url}: indicaciones bloqueadas");
            $this->assertSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('A2')->getProtection()->getLocked(), "{$url}: primera fila de datos libre");
            $this->assertSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle($last.(SpreadsheetReader::MAX_ROWS + 1))->getProtection()->getLocked(), "{$url}: última fila de datos libre");
            $this->assertNotSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('A'.(SpreadsheetReader::MAX_ROWS + 2))->getProtection()->getLocked(), "{$url}: fuera del área de datos bloqueado");
        }
    }

    public function test_curriculum_template_offers_only_yes_or_no_for_the_syllabus(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $book = IOFactory::load($this->templateFile(route('api.v1.coordination.academic.curriculum-import.template')));
        $sheet = $book->getSheetByName('Datos');

        $this->assertSame('Genera sílabo', $sheet->getCell('F1')->getValue());
        $this->assertSame('list', $sheet->getCell('F2')->getDataValidation()->getType());
        $this->assertSame(['Sí', 'No'], [$book->getSheetByName('Listas')->getCell('F1')->getValue(), $book->getSheetByName('Listas')->getCell('F2')->getValue()]);
        $this->assertNull($book->getSheetByName('Listas')->getCell('F3')->getValue());
        $this->assertStringContainsString('Genera sílabo', (string) $sheet->getCell('N2')->getValue());
    }

    public function test_curriculum_import_marks_subjects_without_syllabus_and_keeps_old_files_working(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $header = ['Código', 'Nombre de la materia', 'Ciclo', 'Unidad de organización', 'Modalidad', 'Genera sílabo', 'Horas ACD', 'Horas APE', 'Horas AA', 'Créditos', 'Prerrequisitos (códigos)', 'Correquisitos (códigos)'];
        $url = route('api.v1.coordination.academic.curriculum-import.store');

        $bad = $this->workbook([$header, ['SIL-001', 'Optativa', '1', 'Unidad básica', '', 'Quizás', '48', '32', '64', '3', '', '']]);
        $this->postJson($url, ['file' => $bad, 'confirm' => true])->assertOk()
            ->assertJsonPath('valid', false)->assertJsonPath('rows.0.errors', ['Genera sílabo debe ser Sí o No.']);

        $file = $this->workbook([
            $header,
            ['SIL-001', 'Con sílabo', '1', 'Unidad básica', '', 'Sí', '48', '32', '64', '3', '', ''],
            ['SIL-002', 'Sin sílabo', '1', 'Unidad básica', '', 'No', '48', '32', '64', '3', '', ''],
            ['SIL-003', 'Vacía', '1', 'Unidad básica', '', '', '48', '32', '64', '3', '', ''],
        ]);
        $this->postJson($url, ['file' => $file, 'confirm' => true])->assertOk()->assertJsonPath('created', 3);
        $generates = fn (string $code): bool => Subject::query()->where('codigo_asignatura', $code)->firstOrFail()->genera_silabo;
        $this->assertTrue($generates('SIL-001'));
        $this->assertFalse($generates('SIL-002'));
        $this->assertTrue($generates('SIL-003'));

        $old = $this->workbook([
            ['Código', 'Nombre de la materia', 'Ciclo', 'Unidad de organización', 'Modalidad', 'Horas ACD', 'Horas APE', 'Horas AA', 'Créditos', 'Prerrequisitos (códigos)', 'Correquisitos (códigos)'],
            ['SIL-004', 'De plantilla anterior', '1', 'Unidad básica', '', '48', '32', '64', '3', '', ''],
        ]);
        $this->postJson($url, ['file' => $old, 'confirm' => true])->assertOk()->assertJsonPath('created', 1);
        $this->assertTrue($generates('SIL-004'));
    }

    public function test_names_over_the_form_limit_are_rejected_in_user_and_teacher_imports(): void
    {
        $long = str_repeat('A', 91);
        $teachers = $this->workbook([
            ['Nombres', 'Apellidos', 'Correo'],
            [$long, 'Uno Dos', 'largo@silabos.test'],
            [str_repeat('A', 90), 'Uno Dos', 'justo@silabos.test'],
        ]);
        $this->actingAsRole('coordinador@silabos.test');
        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $teachers, 'confirm' => true])->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('rows.0.errors', ['Los nombres admiten hasta 90 caracteres.'])
            ->assertJsonPath('rows.1.errors', []);

        $users = $this->workbook([
            ['Nombres', 'Apellidos', 'Correo', 'Rol', 'Carrera'],
            ['Ana Luz', $long, 'largo2@silabos.test', 'Administrador', ''],
        ]);
        $this->actingAsRole('admin@silabos.test');
        $this->postJson(route('api.v1.admin.users.import'), ['file' => $users, 'confirm' => true])->assertOk()
            ->assertJsonPath('rows.0.errors', ['Los apellidos admiten hasta 90 caracteres.'])
            ->assertJsonPath('created', 0);
    }

    public function test_teacher_preview_flags_accounts_that_cannot_join_instead_of_failing_the_whole_file(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        User::query()->where('correo_electronico', 'docente@silabos.test')->update(['activo' => false]);
        $file = $this->workbook([
            ['Nombres', 'Apellidos', 'Correo'],
            ['Ana Luz', 'Uno Dos', 'docente@silabos.test'],
            ['Ada Luz', 'Uno Dos', 'admin@silabos.test'],
            ['Eva Luz', 'Uno Dos', 'nuevo.docente@silabos.test'],
        ]);

        $this->postJson(route('api.v1.coordination.academic.teachers.import'), ['file' => $file, 'confirm' => true])->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('rows.0.errors', ['No se puede incorporar esta cuenta. Solicite a Administración que revise su acceso.'])
            ->assertJsonPath('rows.1.errors', ['No se puede incorporar como docente una cuenta con el rol Administrador.'])
            ->assertJsonPath('rows.2.errors', []);
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'nuevo.docente@silabos.test']);
    }

    public function test_empty_rows_count_toward_the_scanned_row_limit(): void
    {
        $this->actingAsRole('admin@silabos.test');
        $header = ['Facultad', 'Modalidad', 'Código de carrera', 'Nombre de la carrera', 'Número de ciclos'];
        $rows = [$header, ...array_fill(0, SpreadsheetReader::MAX_SCANNED_ROWS + 50, [' ', '', '', '', ''])];

        $this->postJson(route('api.v1.admin.academic.careers.import'), ['file' => $this->workbook($rows)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'El archivo tiene demasiadas filas, contando las vacías. Use hasta 500 filas y borre las filas sobrantes.');
    }

    public function test_a_sheet_declaring_thousands_of_columns_is_rejected_before_reading_rows(): void
    {
        $this->actingAsRole('admin@silabos.test');
        $faculty = Faculty::query()->where('activo', true)->firstOrFail();
        $file = $this->workbook([
            ['Facultad', 'Modalidad', 'Código de carrera', 'Nombre de la carrera', 'Número de ciclos'],
            [$faculty->nombre, 'Presencial', 'NC-9', 'Carrera Nueve', '10'],
        ]);
        $zip = new ZipArchive;
        $zip->open((string) $file->getRealPath());
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = (string) preg_replace('/<dimension\b[^>]*\/>/', '', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', (string) preg_replace('/(<worksheet\b[^>]*>)/', '$1<dimension ref="A1:XFD3"/>', $xml, 1));
        $zip->close();

        $this->postJson(route('api.v1.admin.academic.careers.import'), ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'El archivo tiene más de 64 columnas. Use la plantilla .xlsx.');
        $this->assertDatabaseMissing('carreras', ['codigo_carrera' => 'NC-9']);
    }

    public function test_career_and_curriculum_imports_reject_unauthorized_roles_before_reading_the_file(): void
    {
        $this->mock(SpreadsheetReader::class)->shouldNotReceive('read');
        $file = fn () => $this->workbook([['Facultad'], ['X']]);

        $this->actingAsRole('docente@silabos.test');
        $this->postJson(route('api.v1.admin.academic.careers.import'), ['file' => $file()])->assertForbidden();
        $this->postJson(route('api.v1.coordination.academic.curriculum-import.store'), ['file' => $file()])->assertForbidden();

        $this->actingAsRole('admin@silabos.test');
        $this->postJson(route('api.v1.coordination.academic.curriculum-import.store'), ['file' => $file()])->assertForbidden();
    }

    public function test_the_downloaded_career_template_still_imports_within_the_scanned_row_limit(): void
    {
        $this->actingAsRole('admin@silabos.test');
        $faculty = Faculty::query()->where('activo', true)->firstOrFail();
        $path = $this->templateFile(route('api.v1.admin.academic.careers.import-template'));
        $book = IOFactory::load($path);
        $book->getSheetByName('Datos')?->fromArray([$faculty->nombre, 'Presencial', 'NC-T', 'Carrera Plantilla', '8'], null, 'A2');
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->postJson(route('api.v1.admin.academic.careers.import'), ['file' => new UploadedFile($path, 'plantilla.xlsx', null, null, true), 'confirm' => true])
            ->assertOk()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('carreras', ['codigo_carrera' => 'NC-T']);
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
