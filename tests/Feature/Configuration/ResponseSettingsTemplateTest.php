<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\ResponseKind;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResponseSettingsTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONS = [['const' => 'o1', 'title' => 'Aula'], ['const' => 'o2', 'title' => 'Laboratorio'], ['const' => 'o3', 'title' => 'Campo']];

    private SyllabusTemplate $template;

    private ?string $sectionId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
        $this->postJson(route('api.v1.admin.template.store'))->assertCreated();
        $this->template = SyllabusTemplate::institutional();
    }

    private function section(): string
    {
        return $this->sectionId ??= $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'tutorias', 'title' => 'Tutorías'])->assertCreated()->json('section_id');
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function document(array $attrs): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lugar: '], ['type' => 'response', 'attrs' => $attrs]]]]];
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function stored(array $attrs, string $key = 'tutorias'): array
    {
        return $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $this->section()]), [
            'key' => $key, 'label' => 'Tutorías', 'quien_llena' => 'carrera', 'content' => $this->document($attrs),
        ])->assertCreated()->json('field.content.content.0.content.1.attrs');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function rejected(array $attrs, string $message): void
    {
        $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $this->section()]), [
            'key' => 'rechazado', 'label' => 'Tutorías', 'quien_llena' => 'carrera', 'content' => $this->document($attrs),
        ])->assertUnprocessable()->assertJsonFragment([$message]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function normalized(array $attrs): array
    {
        return FieldDocument::normalize($this->document($attrs), 'value')['content'][0]['content'][1]['attrs'];
    }

    public function test_the_administrator_saves_the_settings_of_each_kind(): void
    {
        $this->assertSame(
            ['kind' => 'texto', 'options' => [], 'value' => '', 'required' => false, 'help' => 'Indique el horario de atención.', 'maxLength' => 500],
            $this->stored(['kind' => 'texto', 'required' => false, 'help' => '  Indique el horario de atención. ', 'maxLength' => 500], 'texto'),
        );
        $this->assertSame(
            ['kind' => 'una', 'options' => self::OPTIONS, 'value' => null, 'display' => 'desplegable'],
            $this->stored(['kind' => 'una', 'options' => self::OPTIONS, 'display' => 'desplegable'], 'una'),
        );
        $this->assertSame(
            ['kind' => 'varias', 'options' => self::OPTIONS, 'value' => [], 'minChoices' => 2, 'maxChoices' => 3],
            $this->stored(['kind' => 'varias', 'options' => self::OPTIONS, 'minChoices' => 2, 'maxChoices' => 3], 'varias'),
        );
    }

    public function test_default_settings_keep_the_stored_response_as_it_was(): void
    {
        $legacy = ['kind' => 'una', 'options' => self::OPTIONS, 'value' => 'o2'];
        $this->assertSame($legacy, $this->normalized($legacy));
        $this->assertSame($legacy, $this->normalized([...$legacy, 'required' => true, 'help' => '', 'display' => 'lista']));
        $this->assertSame($legacy, $this->normalized([...$legacy, 'required' => null, 'help' => null, 'display' => null, 'maxLength' => null, 'minChoices' => null, 'maxChoices' => null]));
        $this->assertSame(['kind' => 'texto', 'options' => [], 'value' => 'x'], $this->normalized(['kind' => 'texto', 'value' => 'x', 'maxLength' => ResponseKind::TEXT_LENGTH]));
        $this->assertTrue(ResponseKind::required($legacy));
        $this->assertSame(['texto', 'una', 'varias'], array_keys(ResponseKind::KINDS));
    }

    public function test_each_setting_is_checked_on_the_server(): void
    {
        $this->rejected(['kind' => 'texto', 'required' => 'no'], 'Indique si la respuesta es obligatoria.');
        $this->rejected(['kind' => 'texto', 'help' => str_repeat('a', 201)], 'Escriba una ayuda de hasta 200 caracteres.');
        $this->rejected(['kind' => 'texto', 'maxLength' => 0], 'El límite de caracteres va de 1 a 3000.');
        $this->rejected(['kind' => 'texto', 'maxLength' => 3001], 'El límite de caracteres va de 1 a 3000.');
        $this->rejected(['kind' => 'una', 'options' => self::OPTIONS, 'display' => 'botones'], 'Elija cómo se muestra: lista o desplegable.');
        $this->rejected(['kind' => 'varias', 'options' => self::OPTIONS, 'minChoices' => 4], 'Cuántas elegir va de 1 a 3, el número de opciones.');
        $this->rejected(['kind' => 'varias', 'options' => self::OPTIONS, 'maxChoices' => 0], 'Cuántas elegir va de 1 a 3, el número de opciones.');
        $this->rejected(['kind' => 'varias', 'options' => self::OPTIONS, 'minChoices' => 3, 'maxChoices' => 2], 'El mínimo para elegir no puede superar el máximo.');
        $this->rejected(['kind' => 'varias', 'options' => [], 'minChoices' => 1], 'Escriba las opciones antes de fijar cuántas elegir.');
        $this->rejected(['kind' => 'texto', 'display' => 'desplegable'], 'Una respuesta de texto libre no lleva esa opción.');
        $this->rejected(['kind' => 'una', 'options' => self::OPTIONS, 'maxLength' => 10], 'Una respuesta de elegir una no lleva esa opción.');
    }

    public function test_the_limits_apply_to_the_answer(): void
    {
        $this->assertSame('abc', $this->normalized(['kind' => 'texto', 'maxLength' => 3, 'value' => 'abc'])['value']);
        $this->assertSame([], $this->normalized(['kind' => 'varias', 'options' => self::OPTIONS, 'minChoices' => 2, 'value' => []])['value']);
        foreach ([
            [['kind' => 'texto', 'maxLength' => 3, 'value' => 'abcd'], 'Escriba una respuesta de hasta 3 caracteres.'],
            [['kind' => 'varias', 'options' => self::OPTIONS, 'minChoices' => 2, 'value' => ['o1']], 'Elija al menos 2 opciones.'],
            [['kind' => 'varias', 'options' => self::OPTIONS, 'maxChoices' => 1, 'value' => ['o1', 'o2']], 'Elija como máximo 1 opción.'],
        ] as [$attrs, $message]) {
            try {
                $this->normalized($attrs);
                $this->fail("Se aceptó: {$message}");
            } catch (ValidationException $exception) {
                $this->assertSame(['value' => [$message]], $exception->errors());
            }
        }
    }

    public function test_the_html_keeps_the_settings(): void
    {
        $document = FieldDocument::normalize($this->document(['kind' => 'una', 'options' => self::OPTIONS, 'required' => false, 'help' => 'Elija «uno»', 'display' => 'desplegable']));

        $this->assertEquals($document, FieldDocument::normalize(DocumentHtml::fromHtml(DocumentHtml::toLines($document))));
    }
}
