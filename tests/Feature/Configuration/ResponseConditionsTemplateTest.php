<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponseConditionsTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const YES_NO = [['const' => 'o1', 'title' => 'Sí'], ['const' => 'o2', 'title' => 'No']];

    private const CONDITION = ['response' => 'usa_lab', 'options' => ['o1']];

    private const NOT_BEFORE = 'La condición «Mostrar solo si» usa una respuesta que ya no está antes de esta parte. Elija otra respuesta o quite la condición.';

    private const MISSING_OPTION = 'La condición «Mostrar solo si» usa una opción que la respuesta ya no tiene. Elija otra opción o quite la condición.';

    private SyllabusTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
        $this->postJson(route('api.v1.admin.template.store'))->assertCreated();
        $this->template = SyllabusTemplate::institutional();
        $this->postJson(route('api.v1.admin.template.format', $this->template))->assertOk();
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        $json = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        return ['version' => 1, 'title' => $json['document']['title']['text'], 'sections' => $json['sections']];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>|null  $target
     * @return array<string, mixed>
     */
    private function methods(array $source, ?array $target = null): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '¿Usa laboratorio? '], ['type' => 'response', 'attrs' => $source]]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Detalle: '], ['type' => 'response', 'attrs' => $target ?? ['kind' => 'texto', 'options' => [], 'showIf' => self::CONDITION]]]],
        ]];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function withField(array $draft, string $key, array $changes): array
    {
        foreach ($draft['sections'] as $sectionIndex => $section) {
            foreach ($section['fields'] as $fieldIndex => $field) {
                if ($field['key'] === $key) {
                    $draft['sections'][$sectionIndex]['fields'][$fieldIndex] = [...$field, ...$changes];
                }
            }
        }

        return $draft;
    }

    /** @return array<string, mixed> */
    private function conditioned(): array
    {
        $draft = $this->withField($this->draft(), 'metodologia', ['content' => $this->methods(['kind' => 'una', 'options' => self::YES_NO, 'id' => 'usa_lab'])]);

        return $this->withField($draft, 'tecnicas_ensenanza', ['show_if' => self::CONDITION]);
    }

    private function path(string $key): string
    {
        foreach ($this->draft()['sections'] as $sectionIndex => $section) {
            foreach ($section['fields'] as $fieldIndex => $field) {
                if ($field['key'] === $key) {
                    return "sections.{$sectionIndex}.fields.{$fieldIndex}";
                }
            }
        }

        return '';
    }

    /** @param array<string, mixed> $draft
     * @return list<array{path: string, message: string, blocking: bool}>
     */
    private function problems(array $draft): array
    {
        return $this->postJson(route('api.v1.admin.template.draft.check', $this->template), $draft)->assertOk()->json('problems');
    }

    /** @return array<string, mixed> */
    private function contents(): array
    {
        $contents = FieldDefinition::query()->get()->mapWithKeys(fn (FieldDefinition $field): array => [$field->clave => $field->getRawOriginal('contenido_definicion_campo')])->all();
        ksort($contents);

        return $contents;
    }

    public function test_the_administrator_shows_a_field_only_if_an_earlier_answer_was_chosen(): void
    {
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->conditioned())->assertOk();

        $this->assertSame(['tecnicas_ensenanza' => self::CONDITION], $this->template->fresh()->definition()->fieldConditions());
        $fields = collect($this->getJson(route('api.v1.admin.template.index'))->assertOk()->json('template.sections'))->flatMap(fn (array $section): array => $section['fields'])->keyBy('key');
        $this->assertSame(self::CONDITION, $fields['tecnicas_ensenanza']['show_if']);
        $this->assertArrayNotHasKey('show_if', $fields['metodologia']);
        $exported = collect($this->draft()['sections'])->flatMap(fn (array $section): array => $section['fields'])->keyBy('key');
        $this->assertSame(self::CONDITION, $exported['tecnicas_ensenanza']['show_if']);
    }

    public function test_the_administrator_shows_a_response_only_if_an_earlier_answer_was_chosen(): void
    {
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->conditioned())->assertOk();

        $content = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail()->contenido;
        $this->assertEquals(['kind' => 'una', 'options' => self::YES_NO, 'value' => null, 'id' => 'usa_lab'], $content['content'][0]['content'][1]['attrs']);
        $this->assertEquals(['kind' => 'texto', 'options' => [], 'value' => '', 'showIf' => self::CONDITION], $content['content'][1]['content'][1]['attrs']);
    }

    public function test_a_condition_only_uses_an_earlier_choice_answer(): void
    {
        $earlier = $this->withField($this->conditioned(), 'descripcion', ['show_if' => self::CONDITION]);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $earlier)->assertUnprocessable()
            ->assertJsonValidationErrors([$this->path('descripcion').'.show_if' => self::NOT_BEFORE]);

        $itself = $this->withField($this->draft(), 'metodologia', ['content' => $this->methods(['kind' => 'una', 'options' => self::YES_NO, 'id' => 'usa_lab', 'showIf' => self::CONDITION])]);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $itself)->assertUnprocessable()
            ->assertJsonValidationErrors([$this->path('metodologia').'.content' => self::NOT_BEFORE]);

        $text = $this->withField($this->conditioned(), 'metodologia', ['content' => $this->methods(['kind' => 'texto', 'options' => [], 'id' => 'usa_lab'])]);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $text)->assertUnprocessable()
            ->assertJsonValidationErrors([$this->path('tecnicas_ensenanza').'.show_if' => 'La condición «Mostrar solo si» solo puede usar respuestas de elegir una o elegir varias.']);

        $shape = $this->withField($this->conditioned(), 'tecnicas_ensenanza', ['show_if' => ['response' => 'usa_lab', 'options' => []]]);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $shape)->assertUnprocessable()
            ->assertJsonValidationErrors([$this->path('tecnicas_ensenanza').'.show_if' => 'Elija la respuesta y al menos una de sus opciones para «Mostrar solo si».']);
        $this->assertSame([], $this->template->fresh()->definition()->fieldConditions());
    }

    public function test_a_field_condition_with_an_unknown_key_is_rejected_and_shown_live(): void
    {
        $extra = $this->withField($this->conditioned(), 'tecnicas_ensenanza', ['show_if' => [...self::CONDITION, 'extra' => true]]);
        $message = 'Elija la respuesta y al menos una de sus opciones para «Mostrar solo si».';

        $this->assertContains(['path' => $this->path('tecnicas_ensenanza').'.show_if', 'message' => $message, 'blocking' => true], $this->problems($extra));
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $extra)->assertUnprocessable()
            ->assertJsonValidationErrors([$this->path('tecnicas_ensenanza').'.show_if' => $message]);
        $this->assertSame([], $this->template->fresh()->definition()->fieldConditions());
    }

    public function test_a_condition_left_without_its_answer_or_option_is_shown_live_and_blocks_saving(): void
    {
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->conditioned())->assertOk();
        $this->assertSame([], array_values(array_filter($this->problems($this->draft()), fn (array $problem): bool => $problem['blocking'])));

        $withoutOption = $this->withField($this->draft(), 'metodologia', ['content' => $this->methods(['kind' => 'una', 'options' => [['const' => 'o2', 'title' => 'No']], 'id' => 'usa_lab'])]);
        $this->assertContains(['path' => $this->path('tecnicas_ensenanza').'.show_if', 'message' => self::MISSING_OPTION, 'blocking' => true], $this->problems($withoutOption));
        $this->assertContains(['path' => $this->path('metodologia').'.content', 'message' => self::MISSING_OPTION, 'blocking' => true], $this->problems($withoutOption));
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $withoutOption)->assertUnprocessable();

        $withoutSource = $this->withField($this->draft(), 'metodologia', ['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]);
        $this->assertContains(['path' => $this->path('tecnicas_ensenanza').'.show_if', 'message' => self::NOT_BEFORE, 'blocking' => true], $this->problems($withoutSource));
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $withoutSource)->assertUnprocessable();
        $this->assertSame(['tecnicas_ensenanza' => self::CONDITION], $this->template->fresh()->definition()->fieldConditions());
    }

    public function test_two_responses_cannot_share_an_identifier(): void
    {
        $draft = $this->withField($this->conditioned(), 'recursos_medios', ['content' => $this->methods(['kind' => 'una', 'options' => self::YES_NO, 'id' => 'usa_lab'], ['kind' => 'texto', 'options' => []])]);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertUnprocessable()
            ->assertJsonValidationErrors([$this->path('recursos_medios').'.content' => 'Dos respuestas tienen el mismo identificador. Quite una y vuelva a insertarla.']);
    }

    public function test_a_template_without_conditions_saves_exactly_as_before(): void
    {
        $definition = $this->template->fresh()->getRawOriginal('definicion');
        $contents = $this->contents();
        $export = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->draft())->assertOk();

        $this->assertSame($definition, $this->template->fresh()->getRawOriginal('definicion'));
        $this->assertSame($contents, $this->contents());
        $this->assertSame($export, $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json());
        $this->assertStringNotContainsString('show_if', (string) json_encode($export));
    }
}
