<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private SyllabusTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
        $this->postJson(route('api.v1.admin.template.store'), ['official' => true])->assertCreated();
        $this->template = SyllabusTemplate::query()->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function practices(): array
    {
        return [
            'label' => 'Prácticas de laboratorio',
            'value' => ['practicas' => ['type' => 'array', 'maxItems' => 10, 'initial' => 1, 'title' => 'práctica', 'items' => ['type' => 'object', 'properties' => [
                'tema' => ['type' => 'string', 'maxLength' => 200],
                'horas' => ['type' => 'number', 'maximum' => 99, 'multipleOf' => 1],
            ]]]],
            'rules' => [['code' => 'horas_practicas_insuficientes', 'on' => 'submit', 'message' => 'Las prácticas suman {actual} horas de {expected}.', 'checks' => [['label' => 'Horas', 'sum' => 'practicas.*.horas', 'min' => ['variable' => 'horas_practicas']]]]],
            'layout' => [
                '<table>',
                '<tr><th>N.º</th><th>Tema</th><th>Horas</th></tr>',
                "<tr data-repeat='{\"value\":\"practicas\"}' data-band=\"#EEEEEE\"><td><span data-slot='{\"index\":true}'></span></td><td><span data-slot='{\"value\":\"tema\"}'></span></td><td><span data-slot='{\"value\":\"horas\"}'></span></td></tr>",
                "<tr><th colspan=\"2\">Total</th><th><span data-slot='{\"sum\":\"practicas.*.horas\"}'></span></th></tr>",
                '</table>',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function file(array $override = []): array
    {
        $file = json_decode((string) file_get_contents(base_path(OfficialTemplate::PATH)), true, 512, JSON_THROW_ON_ERROR);
        $file['blocks']['practicas'] = $this->practices();
        $file['sections'][] = ['key' => 'practicas', 'title' => '12. Prácticas', 'fields' => [
            ['key' => 'practicas', 'label' => '', 'quien_llena' => 'docente', 'content' => ['<div data-block="practicas"></div>']],
        ]];

        return array_replace_recursive($file, $override);
    }

    public function test_a_new_block_written_only_in_json_is_validated_filled_expanded_and_checked(): void
    {
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->file())->assertOk();

        $field = FieldDefinition::query()->where('clave', 'practicas')->firstOrFail();
        $this->assertSame([['tema' => '', 'horas' => '']], $field->contenido['content'][0]['attrs']['value']['practicas']);

        $filled = FieldDocument::normalize(['type' => 'doc', 'content' => [['type' => 'documentBlock', 'attrs' => ['kind' => 'practicas', 'value' => ['practicas' => [['tema' => 'Redes', 'horas' => '4'], ['tema' => 'Sockets', 'horas' => '6']]]]]]]);
        $text = FreeText::plainText(TemplateDocumentResolver::resolve($filled, [], []));
        $this->assertStringContainsString('1 | Redes | 4', $text);
        $this->assertStringContainsString('2 | Sockets | 6', $text);
        $this->assertStringContainsString('Total | 10', $text);
        $this->assertSame([['code' => 'horas_practicas_insuficientes', 'message' => 'Las prácticas suman 10 horas de 16.']], DocumentBlock::failures($filled['content'][0], ['horas_practicas' => '16']));
        $this->assertSame([], DocumentBlock::failures($filled['content'][0], ['horas_practicas' => '8']));
    }

    public function test_the_export_carries_the_new_block_and_imports_back_identically(): void
    {
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->file())->assertOk();
        $exported = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        $this->assertArrayHasKey('practicas', $exported['blocks']);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $exported)->assertOk();
        $this->assertEquals($exported, $this->getJson(route('api.v1.admin.template.json.export', $this->template))->json());
    }

    public function test_definition_errors_name_the_exact_place_and_change_nothing(): void
    {
        $broken = $this->file();
        $broken['blocks']['practicas']['layout'] = ["<p><span data-slot='{\"value\":\"nada\"}'></span></p>"];

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $broken)
            ->assertUnprocessable()
            ->assertJsonPath('errors.definition.0', 'blocks.practicas.layout: «nada» no es un dato del valor del bloque.');
        $this->assertNull(FieldDefinition::query()->where('clave', 'practicas')->first());
    }

    public function test_a_frozen_snapshot_keeps_its_design_when_the_template_changes_later(): void
    {
        $frozen = ['template_definition' => $this->template->definition()->toArray()];
        $changed = $this->file();
        $changed['blocks']['evaluacion']['layout'] = ['<p>Evaluación nueva</p>'];
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $changed)->assertOk();
        $node = ['type' => 'documentBlock', 'attrs' => ['kind' => 'evaluacion', 'props' => [], 'value' => []]];

        $live = FreeText::plainText(['type' => 'doc', 'content' => DocumentBlock::expand($node)]);
        $old = app(TemplateContext::class)->forSnapshot($frozen, fn (): string => FreeText::plainText(['type' => 'doc', 'content' => DocumentBlock::expand($node, ['horas_practicas' => '16'])]));

        $this->assertSame('Evaluación nueva', $live);
        $this->assertStringContainsString('2.5 PUNTOS', $old);
    }

    public function test_the_html_layout_round_trips_with_its_directives(): void
    {
        foreach (OfficialTemplate::definition()->blocks() as $kind => $block) {
            $lines = DocumentHtml::toLines(['type' => 'doc', 'content' => $block['layout']]);
            $again = TemplateDefinition::define(['variables' => OfficialTemplate::definition()->variables(), 'blocks' => [$kind => [...array_intersect_key($block, array_flip(['label', 'props', 'value', 'rules'])), 'layout' => $lines]]]);

            $this->assertEquals($block['layout'], $again->block($kind)['layout'], "El diseño de «{$kind}» cambió al pasar por HTML.");
        }
    }

    public function test_block_data_written_with_the_former_names_reads_as_json_schema(): void
    {
        $former = ['respuesta' => ['type' => 'object', 'fields' => [
            'eleccion' => ['type' => 'choice', 'label' => 'Respuesta', 'options' => ['si' => 'Sí', 'no' => 'No']],
            'detalle' => ['type' => 'text', 'max' => 300, 'multiline' => false, 'error' => 'Detalle muy largo.', 'keep_if' => ['value' => 'eleccion', 'equals' => 'si']],
            'filas' => ['type' => 'list', 'min' => 1, 'max' => 5, 'initial' => 1, 'item' => ['horas' => ['type' => 'number', 'digits' => 2, 'decimals' => 1]]],
        ]]];
        $current = ['respuesta' => ['type' => 'object', 'properties' => [
            'eleccion' => ['type' => 'string', 'title' => 'Respuesta', 'oneOf' => [['const' => 'si', 'title' => 'Sí'], ['const' => 'no', 'title' => 'No']]],
            'detalle' => ['type' => 'string', 'maxLength' => 300, 'errorMessage' => 'Detalle muy largo.', 'keep_if' => ['value' => 'eleccion', 'equals' => 'si']],
            'filas' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => ['type' => 'object', 'properties' => ['horas' => ['type' => 'number', 'maximum' => 99.9, 'multipleOf' => 0.1]]]],
        ]]];
        $block = fn (array $value): array => ['label' => 'Bloque', 'value' => $value, 'layout' => ['<p>Bloque</p>']];

        $defined = TemplateDefinition::define(['blocks' => ['bloque' => $block($current)]])->block('bloque');
        $this->assertEquals($defined['value'], TemplateDefinition::define(['blocks' => ['bloque' => $block($former)]])->block('bloque')['value']);
        $this->assertEquals($defined['value'], TemplateDefinition::trusted(['blocks' => ['bloque' => [...$defined, 'value' => $former]]])->block('bloque')['value']);
    }
}
