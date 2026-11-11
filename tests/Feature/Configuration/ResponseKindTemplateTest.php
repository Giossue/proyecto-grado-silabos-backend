<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\ResponseKind;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResponseKindTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONS = [['const' => 'o1', 'title' => 'Presencial'], ['const' => 'o2', 'title' => 'En línea']];

    private SyllabusTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
        $this->postJson(route('api.v1.admin.template.store'))->assertCreated();
        $this->template = SyllabusTemplate::institutional();
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function response(array $attrs): array
    {
        return ['type' => 'response', 'attrs' => $attrs];
    }

    /**
     * @param  array<string, mixed>  $inParagraph
     * @param  array<string, mixed>  $inCell
     * @return array<string, mixed>
     */
    private function document(array $inParagraph, array $inCell): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Horas de tutoría: '], $this->response($inParagraph)]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [['type' => 'tableHeader', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Modalidad']]]]]]],
                ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [['type' => 'paragraph', 'content' => [$this->response($inCell)]]]]]],
            ]],
        ]];
    }

    private ?string $sectionId = null;

    private function section(): string
    {
        return $this->sectionId ??= $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'tutorias', 'title' => 'Tutorías'])->assertCreated()->json('section_id');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function rejected(array $attrs, string $message): void
    {
        $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $this->section()]), [
            'key' => 'tutorias', 'label' => 'Tutorías', 'quien_llena' => 'carrera',
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$this->response($attrs)]]]],
        ])->assertUnprocessable()->assertJsonFragment([$message]);
    }

    public function test_the_administrator_places_free_text_and_a_choice_in_a_field_and_in_a_table_cell(): void
    {
        foreach ([FieldDefinition::CAREER] as $responsible) {
            $content = $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $this->section()]), [
                'key' => "tutorias_{$responsible}", 'label' => 'Tutorías', 'quien_llena' => $responsible,
                'content' => $this->document(['kind' => 'texto', 'value' => '12'], ['kind' => 'varias', 'options' => [['const' => 'o1', 'title' => '  Presencial '], ['const' => 'o2', 'title' => 'En línea']], 'value' => ['o2']]),
            ])->assertCreated()->json('field.content');

            $this->assertSame(['kind' => 'texto', 'options' => [], 'value' => ''], $content['content'][0]['content'][1]['attrs']);
            $this->assertSame(['kind' => 'varias', 'options' => self::OPTIONS, 'value' => []], $content['content'][1]['content'][1]['content'][0]['content'][0]['content'][0]['attrs']);
        }
        $this->assertSame(['texto', 'una', 'varias'], array_keys(ResponseKind::KINDS));
    }

    public function test_a_fixed_field_does_not_take_response_kinds(): void
    {
        $content = $this->document(['kind' => 'texto'], ['kind' => 'una', 'options' => self::OPTIONS]);
        $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $this->section()]), [
            'key' => 'tutorias', 'label' => 'Tutorías', 'quien_llena' => 'institucional', 'content' => $content,
        ])->assertUnprocessable()->assertJsonValidationErrors(['content' => 'Un campo Institucional no lleva tipos de respuesta. Cambie quién lo llena o quite la respuesta.']);

        $field = $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $this->section()]), [
            'key' => 'tutorias', 'label' => 'Tutorías', 'quien_llena' => 'carrera', 'content' => $content,
        ])->assertCreated()->json('field.id');
        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$this->template, $field]), ['quien_llena' => 'institucional'])
            ->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->assertSame('carrera', FieldDefinition::query()->findOrFail($field)->quien_llena);
    }

    public function test_the_kind_comes_from_the_catalog_and_the_options_are_written_once_each(): void
    {
        $this->rejected(['kind' => 'fecha'], 'Elija un tipo de respuesta: Texto libre, Elegir una o Elegir varias.');
        $this->rejected(['kind' => 'una', 'options' => [['const' => 'o1', 'title' => 'Sí'], ['const' => 'o2', 'title' => 'Sí']]], 'Hay opciones repetidas. Deje cada opción una sola vez.');
        $this->rejected(['kind' => 'una', 'options' => [['const' => 'o1', 'title' => '  ']]], 'Escriba el texto de cada opción, de hasta 120 caracteres.');
        $this->rejected(['kind' => 'una', 'options' => [['const' => 'o1', 'title' => str_repeat('a', 121)]]], 'Escriba el texto de cada opción, de hasta 120 caracteres.');
        $this->rejected(['kind' => 'texto', 'options' => self::OPTIONS], 'Una respuesta de texto libre no lleva opciones.');
        $this->rejected(['kind' => 'varias', 'options' => array_map(fn (int $index): array => ['const' => "o{$index}", 'title' => "Opción {$index}"], range(1, 51))], 'Una respuesta admite hasta 50 opciones.');
    }

    public function test_the_number_kind_no_longer_exists(): void
    {
        $this->rejected(['kind' => 'numero'], 'Elija un tipo de respuesta: Texto libre, Elegir una o Elegir varias.');
        $this->rejected(['kind' => 'numero', 'value' => '12'], 'Elija un tipo de respuesta: Texto libre, Elegir una o Elegir varias.');
    }

    public function test_the_answer_follows_its_kind(): void
    {
        $normalize = fn (array $attrs): array => FieldDocument::normalize(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$this->response($attrs)]]]], 'value')['content'][0]['content'][0]['attrs'];

        $this->assertSame('12,5', $normalize(['kind' => 'texto', 'value' => ' 12,5 '])['value']);
        $this->assertSame('o2', $normalize(['kind' => 'una', 'options' => self::OPTIONS, 'value' => 'o2'])['value']);
        $this->assertSame(['o2', 'o1'], $normalize(['kind' => 'varias', 'options' => self::OPTIONS, 'value' => ['o2', 'o1', 'o2']])['value']);
        $this->assertNull($normalize(['kind' => 'una', 'options' => [], 'value' => null])['value']);

        foreach ([
            [['kind' => 'una', 'options' => self::OPTIONS, 'value' => 'o9'], 'Elija solo entre las opciones de la respuesta.'],
            [['kind' => 'una', 'options' => self::OPTIONS, 'value' => ['o1', 'o2']], 'Elija solo entre las opciones de la respuesta.'],
            [['kind' => 'varias', 'options' => self::OPTIONS, 'value' => ['o1', 'inventada']], 'Elija solo entre las opciones de la respuesta.'],
            [['kind' => 'una', 'options' => [], 'value' => 'o1'], 'Esta respuesta todavía no tiene opciones para elegir.'],
        ] as [$attrs, $message]) {
            try {
                $normalize($attrs);
                $this->fail("Se aceptó: {$message}");
            } catch (ValidationException $exception) {
                $this->assertSame(['value' => [$message]], $exception->errors());
            }
        }
    }

    public function test_the_html_of_the_template_keeps_the_kind_the_options_and_the_answer(): void
    {
        $document = FieldDocument::normalize($this->document(['kind' => 'texto', 'value' => '3'], ['kind' => 'una', 'options' => [['const' => 'o1', 'title' => "D'Hondt & \"otros\""]], 'value' => 'o1']));

        $lines = DocumentHtml::toLines($document);
        $this->assertStringContainsString('<span data-response="{&quot;kind&quot;:&quot;texto&quot;', $lines[0]);
        $this->assertEquals($document, FieldDocument::normalize(DocumentHtml::fromHtml($lines)));

        $legacy = FieldDocument::normalize(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Carrera: '], ['type' => 'variable', 'attrs' => ['id' => 'nombre_carrera']]]]]]);
        $this->assertSame(['<p>Carrera: <span data-variable="nombre_carrera"></span></p>'], DocumentHtml::toLines($legacy));
        $this->assertEquals($legacy, FieldDocument::normalize(DocumentHtml::fromHtml(DocumentHtml::toLines($legacy))));
    }

    public function test_the_document_prints_what_was_answered_as_plain_text(): void
    {
        $document = FieldDocument::normalize(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Horas: '], $this->response(['kind' => 'texto', 'value' => '12,5'])]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Modalidad: '], $this->response(['kind' => 'una', 'options' => self::OPTIONS, 'value' => 'o2'])]],
            ['type' => 'paragraph', 'content' => [$this->response(['kind' => 'varias', 'options' => self::OPTIONS, 'value' => ['o2', 'o1']])]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sin contestar: '], $this->response(['kind' => 'una', 'options' => self::OPTIONS])]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Carrera: '], ['type' => 'variable', 'attrs' => ['id' => 'nombre_carrera']]]],
        ]]);

        $this->assertSame("Horas: 12,5\nModalidad: En línea\nEn línea\nPresencial\nSin contestar: \nCarrera: ", FreeText::plainText($document));
        $resolved = FieldDocument::resolve($document, ['nombre_carrera' => 'Software']);
        $this->assertSame([['type' => 'text', 'text' => 'Horas: '], ['type' => 'text', 'text' => '12,5', 'marks' => []]], $resolved['content'][0]['content']);
        $this->assertSame([['type' => 'text', 'text' => 'En línea', 'marks' => []], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'Presencial', 'marks' => []]], $resolved['content'][2]['content']);
        $this->assertSame([['type' => 'text', 'text' => 'Sin contestar: ']], $resolved['content'][3]['content']);
        $this->assertSame('Carrera: Software', FreeText::plainText($resolved['content'][4]));
        $this->assertSame([], TemplateDocument::nodes($resolved, 'response'));
    }

    public function test_the_template_json_exports_and_imports_the_response_kinds(): void
    {
        $this->postJson(route('api.v1.admin.template.format', $this->template))->assertSuccessful();
        $field = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail();
        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$this->template, $field]), [
            'content' => $this->document(['kind' => 'texto'], ['kind' => 'una', 'options' => self::OPTIONS]),
        ])->assertOk();

        $json = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();
        $exported = collect($json['sections'])->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'metodologia');
        $this->assertStringContainsString('data-response=', implode('', (array) $exported['content']));

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $json)->assertSuccessful();
        $imported = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail()->contenido;
        $this->assertEquals(['kind' => 'una', 'options' => self::OPTIONS, 'value' => null], $imported['content'][1]['content'][1]['content'][0]['content'][0]['content'][0]['attrs']);
    }
}
