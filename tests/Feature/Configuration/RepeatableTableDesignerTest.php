<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Domain\Blocks\BlockDefinition;
use App\Modules\Configuration\Domain\Blocks\BlockRules;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\TestCase;

class RepeatableTableDesignerTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use RefreshDatabase;

    private SyllabusTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        $this->actingAsRole('admin@silabos.test');
        $this->postJson(route('api.v1.admin.template.store'), [])->assertCreated();
        $this->template = SyllabusTemplate::query()->firstOrFail();
        $this->postJson(route('api.v1.admin.template.format', $this->template))->assertOk();
    }

    public function test_the_official_tables_open_in_the_designer_or_say_why_they_cannot(): void
    {
        $designs = $this->getJson(route('api.v1.admin.template.show', $this->template))->assertOk()->json('blockDesigns');

        $books = $designs['bibliografia']['design'];
        $this->assertNull($designs['bibliografia']['reason']);
        $this->assertSame(['Bibliografía', 'libros', 'libro', 1, 40, 1], [$books['label'], $books['list'], $books['item'], $books['min'], $books['max'], $books['initial']]);
        $this->assertSame(['Autor', 'Título', 'Año', 'Ciudad', 'Editorial', 'ISBN', 'Código'], array_column($books['columns'], 'title'));
        $this->assertSame(['text', 1000, false, null, true], [$books['columns'][1]['type'], $books['columns'][1]['max_length'], $books['columns'][1]['multiline'], $books['columns'][1]['width'], $books['columns'][1]['required']]);
        $this->assertFalse($books['columns'][0]['required']);

        $this->assertNull($designs['unidades']['design']);
        $this->assertSame('Tiene listas o grupos dentro de cada fila. Para cambiarla use Opciones avanzadas → Editar JSON.', $designs['unidades']['reason']);
        $this->assertSame('No es una sola lista de filas. Para cambiarla use Opciones avanzadas → Editar JSON.', $designs['identificacion']['reason']);
        $this->assertSame('No tiene datos que llene el docente: es una tabla fija. Para cambiarla use Opciones avanzadas → Editar JSON.', $designs['evaluacion']['reason']);
    }

    public function test_the_administrator_designs_a_new_table_and_saves_it_in_a_field(): void
    {
        $response = $this->postJson($this->designRoute(), $this->practices())->assertOk();

        $design = $response->json('design');
        $this->assertSame('practicas', $response->json('kind'));
        $this->assertSame(['tema', 'modalidad', 'horas'], array_column($design['columns'], 'key'));
        $this->assertSame([['value' => 'presencial', 'label' => 'Presencial'], ['value' => 'virtual', 'label' => 'Virtual']], $design['columns'][1]['options']);
        $this->assertSame([['tema' => '', 'modalidad' => null, 'horas' => ''], ['tema' => '', 'modalidad' => null, 'horas' => '']], $response->json('initial.practicas'));
        $definition = $response->json('definition');
        $this->assertSame([['code' => 'tema_obligatoria', 'on' => 'submit', 'message' => 'Llene «Tema» en cada práctica: {detail}.', 'checks' => [['filled' => 'practicas.*.tema', 'equals' => ['count' => 'practicas'], 'label' => 'Tema']]]], $definition['rules']);
        $this->assertSame([200], $definition['layout'][0]['content'][0]['content'][0]['attrs']['colwidth']);

        $draft = $this->draft();
        $draft['sections'][0]['fields'][] = ['key' => 'practicas_lab', 'label' => 'Prácticas', 'quien_llena' => 'carrera', 'content' => ['type' => 'doc', 'content' => [
            ['type' => 'documentBlock', 'attrs' => ['kind' => 'practicas', 'props' => [], 'value' => $response->json('initial')]],
        ]]];
        $draft['block_changes'] = ['practicas' => $definition];
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $draft)->assertOk();

        $this->assertSame($definition, $this->stored()['blocks']['practicas']);
        $this->assertSame($design, $this->getJson(route('api.v1.admin.template.show', $this->template))->json('blockDesigns.practicas.design'));
    }

    public function test_the_administrator_edits_columns_types_and_row_limits_of_an_official_table(): void
    {
        $design = $this->getJson(route('api.v1.admin.template.show', $this->template))->json('blockDesigns.bibliografia.design');
        $design['max'] = 20;
        $design['columns'][2]['title'] = 'Año de publicación';
        $design['columns'][3]['width'] = 120;
        $design['columns'][] = ['title' => 'Enlace', 'type' => 'text', 'max_length' => 300, 'multiline' => false, 'width' => null, 'required' => true];

        $definition = $this->postJson($this->designRoute(), $design)->assertOk()->json('definition');
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$this->draft(), 'block_changes' => ['bibliografia' => $definition]])->assertOk();

        $books = $this->stored()['blocks']['bibliografia'];
        $lines = implode('', $this->export()['blocks']['bibliografia']['layout']);
        $list = $books['value']['libros'];
        $this->assertSame([1, 20, 1], [$list['minItems'], $list['maxItems'], $list['initial']]);
        $this->assertArrayNotHasKey('errorMessage', $list);
        $this->assertSame('El dato «Autor» del libro supera los 1000 caracteres.', $list['items']['properties']['autor']['errorMessage']);
        $this->assertArrayNotHasKey('errorMessage', $list['items']['properties']['anio']);
        $this->assertSame(['type' => 'string', 'title' => 'Enlace', 'maxLength' => 300, 'multiline' => false], $list['items']['properties']['enlace']);
        $this->assertSame(['libros_sin_titulo', 'enlace_obligatoria'], array_column($books['rules'], 'code'));
        $this->assertSame('Cada libro necesita su título para poder enviar', $books['rules'][0]['hint']);
        $this->assertSame('codigo', $books['catalog']['map']['format']);
        $this->assertStringContainsString('<th style="background:#C3D4EA;font-weight:bold">Año de publicación</th>', $lines);
        $this->assertStringContainsString('<td data-width="120">', $lines);
    }

    public function test_a_required_column_stops_the_submission_and_the_row_limits_hold_when_saving(): void
    {
        $definition = BlockDefinition::define('practicas', $this->postJson($this->designRoute(), $this->practices())->json('definition'), []);
        $rows = [['tema' => 'Medición', 'modalidad' => 'virtual', 'horas' => '2'], ['tema' => ' ', 'modalidad' => null, 'horas' => '']];

        $failures = BlockRules::failures($definition['rules'], ['practicas' => $rows], [], 'submit');
        $this->assertSame([['code' => 'tema_obligatoria', 'message' => 'Llene «Tema» en cada práctica: Tema 1 de 2.']], array_map(fn (array $failure): array => ['code' => $failure['code'], 'message' => $failure['message']], $failures));
        $rows[1]['tema'] = 'Calibración';
        $this->assertSame([], BlockRules::failures($definition['rules'], ['practicas' => $rows], [], 'submit'));

        try {
            BlockDefinition::normalize('practicas', $definition, ['value' => ['practicas' => array_fill(0, 11, $rows[0])]]);
            $this->fail('Once filas no deberían aceptarse.');
        } catch (ValidationException $exception) {
            $this->assertSame('«práctica» admite hasta 10 elementos.', collect($exception->errors())->flatten()->first());
        }
        $this->expectException(ValidationException::class);
        BlockDefinition::normalize('practicas', $definition, ['value' => ['practicas' => [['tema' => 'x', 'modalidad' => 'otra', 'horas' => '']]]]);
    }

    public function test_an_untouched_table_designs_back_to_the_same_definition_and_saving_without_changes_keeps_the_template_identical(): void
    {
        $before = $this->export();
        $stored = $this->stored();
        $design = $this->getJson(route('api.v1.admin.template.show', $this->template))->json('blockDesigns.bibliografia.design');

        $definition = $this->postJson($this->designRoute(), $design)->assertOk()->json('definition');
        $this->assertSame($stored['blocks']['bibliografia'], $definition);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->draft())->assertOk();
        $this->assertSame($before, $this->export());
        $this->assertSame($stored, $this->stored());
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$this->draft(), 'block_changes' => ['bibliografia' => $definition]])->assertOk();
        $this->assertSame($before, $this->export());
        $this->assertSame($stored, $this->stored());
    }

    public function test_the_server_says_in_spanish_what_is_wrong_with_a_design(): void
    {
        $design = $this->practices();
        $design['columns'][0]['title'] = '';
        $design['columns'][1]['options'] = [];
        $design['max'] = 0;
        $this->postJson($this->designRoute(), $design)->assertUnprocessable()
            ->assertJsonValidationErrors(['columns.0.title' => 'Escriba el nombre de la columna.', 'columns.1.options' => 'Agregue al menos una opción.', 'max']);
        $this->postJson($this->designRoute(), [...$this->practices(), 'min' => 5, 'max' => 3, 'initial' => 3])->assertUnprocessable()
            ->assertJsonPath('errors.max.0', 'El máximo de filas no puede ser menor que el mínimo.');
        $this->postJson($this->designRoute(), [...$this->practices(), 'kind' => 'bibliografia', 'new' => true])->assertUnprocessable()
            ->assertJsonPath('errors.kind.0', 'Ya existe una tabla con ese nombre interno.');

        $books = $this->getJson(route('api.v1.admin.template.show', $this->template))->json('blockDesigns.bibliografia.design');
        $books['columns'] = array_values(array_filter($books['columns'], fn (array $column): bool => $column['key'] !== 'isbn'));
        $this->postJson($this->designRoute(), $books)->assertUnprocessable()
            ->assertJsonPath('errors.definition.0', 'blocks.bibliografia.catalog.map.isbn: debe ser una columna de texto de la lista.');

        $broken = $this->postJson($this->designRoute(), $this->practices())->json('definition');
        $broken['rules'][0]['checks'][0]['filled'] = 'practicas.*.nada';
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$this->draft(), 'block_changes' => ['practicas' => $broken]])
            ->assertUnprocessable()->assertJsonPath('errors.definition.0', 'blocks.practicas.rules.0.checks.0.filled: «practicas.*.nada» no existe en el valor del bloque.');
        $problems = $this->postJson(route('api.v1.admin.template.draft.check', $this->template), [...$this->draft(), 'block_changes' => ['practicas' => $broken]])->assertOk()->json('problems');
        $this->assertContains(['path' => 'definition', 'message' => 'blocks.practicas.rules.0.checks.0.filled: «practicas.*.nada» no existe en el valor del bloque.', 'blocking' => true], $problems);
    }

    public function test_new_columns_and_options_keep_the_place_where_they_were_added(): void
    {
        $design = $this->getJson(route('api.v1.admin.template.show', $this->template))->json('blockDesigns.bibliografia.design');
        $columns = $design['columns'];
        array_splice($columns, 3, 0, [['title' => 'Edición', 'type' => 'choice', 'options' => [['label' => 'Primera'], ['value' => 'segunda', 'label' => 'Segunda'], ['label' => 'Tercera']], 'width' => null, 'required' => false]]);
        array_unshift($columns, ['title' => 'Orden', 'type' => 'number', 'maximum' => 99, 'decimals' => 0, 'width' => null, 'required' => false]);

        $response = $this->postJson($this->designRoute(), [...$design, 'columns' => $columns])->assertOk();

        $this->assertSame(['orden', 'autor', 'titulo', 'anio', 'edicion', 'ciudad', 'editorial', 'isbn', 'codigo'], array_keys($response->json('definition.value.libros.items.properties')));
        $this->assertSame(['primera', 'segunda', 'tercera'], array_column($response->json('definition.value.libros.items.properties.edicion.oneOf'), 'const'));
    }

    public function test_two_choice_columns_may_share_options_but_one_column_may_not_repeat_them(): void
    {
        $yesNo = [['label' => 'Sí'], ['label' => 'No']];
        $design = [...$this->practices(), 'columns' => [
            ['title' => 'Asistió', 'type' => 'choice', 'options' => $yesNo, 'width' => null, 'required' => false],
            ['title' => 'Aprobó', 'type' => 'choice', 'options' => $yesNo, 'width' => null, 'required' => false],
        ]];

        $this->postJson($this->designRoute(), $design)->assertOk();

        $design['columns'][1]['options'] = [['label' => 'Sí'], ['label' => 'sí ']];
        $this->postJson($this->designRoute(), $design)->assertUnprocessable()
            ->assertJsonValidationErrors(['columns.1.options.1.label' => 'La opción está repetida.'])
            ->assertJsonMissingValidationErrors(['columns.0.options.0.label', 'columns.0.options.1.label']);
    }

    public function test_tables_the_designer_cannot_represent_are_not_changed_from_the_editor(): void
    {
        $stored = $this->stored()['blocks'];
        $message = 'La tabla «Identificación institucional» no se puede cambiar desde el editor. Para cambiarla use Opciones avanzadas → Editar JSON.';
        $changed = [...$stored['identificacion'], 'label' => 'Otra identificación'];

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$this->draft(), 'block_changes' => ['identificacion' => $changed]])
            ->assertUnprocessable()->assertJsonPath('errors.definition.0', $message);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$this->draft(), 'block_changes' => ['identificacion' => $this->postJson($this->designRoute(), $this->practices())->json('definition')]])
            ->assertUnprocessable()->assertJsonPath('errors.definition.0', $message);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$this->draft(), 'block_changes' => ['nueva' => $stored['unidades']]])
            ->assertUnprocessable()->assertJsonPath('errors.definition.0', 'La tabla «Distribución y planificación de las unidades» no se puede cambiar desde el editor. Para cambiarla use Opciones avanzadas → Editar JSON.');
        $this->postJson($this->designRoute(), [...$this->practices(), 'kind' => 'unidades', 'new' => false])
            ->assertUnprocessable()->assertJsonPath('errors.kind.0', 'La tabla «Distribución y planificación de las unidades» no se puede cambiar desde el editor. Para cambiarla use Opciones avanzadas → Editar JSON.');
        $this->assertSame($stored, $this->stored()['blocks']);

        $file = $this->export();
        $file['blocks']['identificacion']['label'] = 'Identificación del JSON';
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $file)->assertOk();
        $this->assertSame('Identificación del JSON', $this->stored()['blocks']['identificacion']['label']);
    }

    public function test_only_the_administrator_designs_tables(): void
    {
        $this->actingAsRole('docente@silabos.test');

        $this->postJson($this->designRoute(), $this->practices())->assertForbidden();
    }

    private function designRoute(): string
    {
        return route('api.v1.admin.template.blocks.design', $this->template);
    }

    /** @return array<string, mixed> */
    private function practices(): array
    {
        return [
            'kind' => 'practicas', 'new' => true, 'label' => 'Prácticas de laboratorio', 'list' => 'practicas', 'item' => 'práctica',
            'min' => 1, 'max' => 10, 'initial' => 2, 'band' => null,
            'columns' => [
                ['title' => 'Tema', 'type' => 'text', 'max_length' => 200, 'multiline' => false, 'width' => 200, 'required' => true],
                ['title' => 'Modalidad', 'type' => 'choice', 'options' => [['label' => 'Presencial'], ['label' => 'Virtual']], 'width' => null, 'required' => false],
                ['title' => 'Horas', 'type' => 'number', 'maximum' => 99, 'decimals' => 0, 'width' => null, 'required' => false],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        $json = $this->export();

        return ['version' => 1, 'title' => $json['document']['title']['text'], 'sections' => $json['sections']];
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return SyllabusTemplate::query()->firstOrFail()->definition()->toArray();
    }

    /** @return array<string, mixed> */
    private function export(): array
    {
        return $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();
    }

    private function actingAsRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $assignment = $user->roleAssignments()->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $assignment->id]);
    }
}
