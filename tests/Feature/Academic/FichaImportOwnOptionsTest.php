<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Application\FichaWorkbookContext;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Spreadsheets\FichaWorkbook;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BuildsResponseKinds;
use Tests\TestCase;

class FichaImportOwnOptionsTest extends TestCase
{
    use BuildsResponseKinds, RefreshDatabase;

    private const BASE = ['Aula', 'Laboratorio'];

    private const OWN = ['Aula', 'Taller', 'Campo'];

    private Subject $subject;

    private Subject $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seed(DatabaseSeeder::class);
        $this->subject = Subject::query()->firstOrFail();
        $this->other = $this->subject->replicate();
        $this->other->codigo_asignatura = 'OTRA-1';
        $this->other->nombre = 'Otra materia';
        $this->other->save();
        $this->templateWithResponseField($this->response('una', self::BASE));
    }

    private function import(Subject $subject, string $technique): TestResponse
    {
        $definition = app(FichaWorkbookContext::class)->for((string) $subject->carrera_id);
        $book = app(FichaWorkbook::class)->create($definition);
        $index = array_search($subject->id, array_column($definition->subjects, 'id'), true);
        $this->assertIsInt($index);
        $field = collect($definition->fields)->firstWhere('key', 'metodologia');
        $book->getSheetByName($field['sheet'])->fromArray([[$technique, '4']], null, 'D'.(4 + $index * 40));
        $path = tempnam(sys_get_temp_dir(), 'ficha-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $file = new UploadedFile($path, 'proyecto.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $detail = $this->asUser('coordinador@silabos.test')->post(route('api.v1.coordination.ficha-imports.spreadsheet'), ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('preview');

        return $this->post(route('api.v1.coordination.ficha-imports.apply'), ['file' => $file, 'fingerprint' => $detail['fingerprint'], 'file_hash' => $detail['file_hash'], 'subjects' => [$subject->id], 'fields' => [$subject->id => ['metodologia']]], ['Accept' => 'application/json']);
    }

    /** @return array<string, mixed> */
    private function stored(Subject $subject): array
    {
        return FieldValue::query()->where('asignatura_id', $subject->id)->where('definicion_campo_id', $this->responseField->id)->firstOrFail()->valor;
    }

    public function test_the_import_keeps_the_own_options_and_the_marker_of_the_subject(): void
    {
        $this->saveSetup($this->withResponses($this->response('una', self::OWN)), subject: $this->subject)->assertOk();

        $this->import($this->subject, 'Exposición')->assertOk();

        $stored = $this->stored($this->subject);
        $attrs = $stored['content'][1]['content'][0]['attrs'];
        $this->assertSame(self::OWN, array_column($attrs['options'], 'title'));
        $this->assertTrue($attrs['customOptions']);
        $this->assertStringContainsString('Exposición', FreeText::plainText($stored['content'][2]));
    }

    public function test_the_import_of_a_subject_without_own_options_is_unchanged(): void
    {
        $this->import($this->other, 'Exposición')->assertOk();

        $stored = $this->stored($this->other);
        $attrs = $stored['content'][1]['content'][0]['attrs'];
        $this->assertSame(self::BASE, array_column($attrs['options'], 'title'));
        $this->assertArrayNotHasKey('customOptions', $attrs);
        $this->assertEquals(['kind' => 'una', 'options' => [['const' => 'o1', 'title' => 'Aula'], ['const' => 'o2', 'title' => 'Laboratorio']], 'value' => null], $attrs);
        $this->assertSame("Estrategias de la asignatura\n\nTécnica | Horas\nExposición | 4", FreeText::plainText($stored));
    }

    public function test_an_imported_choice_outside_the_options_of_the_subject_rejects_the_row(): void
    {
        $this->saveSetup($this->withResponses($this->response('una', ['Aula'])), subject: $this->subject)->assertOk();
        $imported = FieldResponse::template(FieldDocument::normalize($this->responseField->contenido));
        $imported['content'][1]['content'][0]['attrs']['value'] = 'o2';

        try {
            FieldResponse::keepOwnOptions($this->responseField->contenido, $imported, $this->stored($this->subject), 'file');
            $this->fail('La importación debía rechazar la opción.');
        } catch (ValidationException $exception) {
            $this->assertSame(['file' => ['Elija solo entre las opciones de la respuesta.']], $exception->errors());
        }

        $imported['content'][1]['content'][0]['attrs']['value'] = 'o1';
        $kept = FieldResponse::keepOwnOptions($this->responseField->contenido, $imported, $this->stored($this->subject), 'file');
        $this->assertSame('o1', $kept['content'][1]['content'][0]['attrs']['value']);
        $this->assertSame(['Aula'], array_column($kept['content'][1]['content'][0]['attrs']['options'], 'title'));
        $this->assertTrue($kept['content'][1]['content'][0]['attrs']['customOptions']);
    }
}
