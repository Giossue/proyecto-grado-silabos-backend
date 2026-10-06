<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Application\Actions\SaveTemplateDocument;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class TemplateDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_institutional_design_preserves_complex_grid_and_uses_descriptive_variables(): void
    {
        $doc = TemplateDocument::normalize(TemplateDocumentDefaults::identification(), array_keys(TemplateVariables::definitions()));
        $this->assertCount(18, TemplateDocument::nodes($doc, 'tableRow'));
        $keys = array_column(array_column(TemplateDocument::nodes($doc, 'variable'), 'attrs'), 'id');
        $this->assertContains('nombre_carrera', $keys);
        $this->assertContains('nombre_facultad', $keys);
        $this->assertNotContains('carrera', $keys);
        $this->assertCount(5, TemplateDocument::nodes($doc, 'field'));
        $this->assertStringNotContainsString('fontSize', json_encode($doc, JSON_THROW_ON_ERROR));
        $values = TemplateVariables::resolve(['identification' => ['career' => 'Carrera real del expediente']]);
        $this->assertSame('Carrera real del expediente', $values['nombre_carrera']);
    }

    public function test_non_selection_field_normalizes_legacy_empty_options_as_absent(): void
    {
        $document = ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'field',
                'attrs' => [
                    'key' => 'formacion_experiencia',
                    'label' => 'Formación y experiencia',
                    'kind' => 'texto_largo',
                    'options' => [],
                ],
            ]],
        ]]];

        $normalized = TemplateDocument::normalize($document, []);

        $this->assertNull(TemplateDocument::nodes($normalized, 'field')[0]['attrs']['options']);
    }

    public function test_legacy_markdown_field_kind_is_canonicalized_to_long_text(): void
    {
        $document = ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'field',
                'attrs' => [
                    'key' => 'descripcion',
                    'label' => 'Descripción',
                    'kind' => 'markdown',
                ],
            ]],
        ]]];

        $normalized = TemplateDocument::normalize($document, []);

        $this->assertSame('texto_largo', TemplateDocument::nodes($normalized, 'field')[0]['attrs']['kind']);
    }

    public function test_text_uses_the_global_content_size(): void
    {
        $text = fn (string $value): array => [
            'type' => 'text',
            'text' => $value,
            'marks' => [[
                'type' => 'textStyle',
                'attrs' => [
                    'fontFamily' => 'Times New Roman',
                    'fontSize' => '7pt',
                    'color' => '#000000',
                ],
            ]],
        ];
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [$text('Fuera')]],
            ['type' => 'table', 'content' => [[
                'type' => 'tableRow',
                'content' => [[
                    'type' => 'tableCell',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [$text('Dentro')],
                    ]],
                ]],
            ]]],
        ]];

        $normalized = TemplateDocument::normalize($document, []);
        $texts = TemplateDocument::nodes($normalized, 'text');

        $this->assertSame('Times New Roman', $texts[0]['marks'][0]['attrs']['fontFamily']);
        $this->assertArrayNotHasKey('fontSize', $texts[0]['marks'][0]['attrs']);
        $this->assertArrayNotHasKey('fontSize', $texts[1]['marks'][0]['attrs']);
        $this->assertArrayNotHasKey('fontFamily', $texts[1]['marks'][0]['attrs']);
        $this->assertSame('#000000', $texts[1]['marks'][0]['attrs']['color']);
    }

    public function test_admin_saves_layout_and_creates_only_teacher_editable_fields_with_conflict_control(): void
    {
        $template = $this->template();
        $block = $template->fields()->where('clave', 'objetivo_general')->firstOrFail()->block;
        $doc = $this->document();
        $fingerprint = SaveTemplateDocument::fingerprint($block);
        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);
        $this->patch($url, [
            'document' => $doc,
            'fingerprint' => $fingerprint,
            'heredado' => true,
            'properties' => [[
                'key' => 'respuesta_extra',
                'label' => 'Cantidad configurada',
                'help' => 'Indique una cantidad.',
            ]],
        ])
            ->assertSuccessful()->assertSuccessful();
        $new = $block->fields()->where('clave', 'respuesta_extra')->firstOrFail();
        $this->assertTrue($new->editable_docente);
        $this->assertFalse($new->heredado);
        $this->assertSame('numero', $new->tipo);
        $this->assertSame('Cantidad configurada', $new->etiqueta);
        $this->assertSame('Indique una cantidad.', $new->ayuda);
        $this->patchJson(route('api.v1.admin.template.fields.update', [$template, $new]), [
            'block_id' => $block->id, 'key' => $new->clave, 'label' => $new->etiqueta,
            'content_type' => 'text', 'help' => 'Indique una cantidad.',
        ])->assertSuccessful();
        $this->assertSame('numero', $new->fresh()->tipo);
        $saved = $block->fresh()->configuracion['document'];
        $this->patch($url, ['document' => $doc, 'fingerprint' => $fingerprint])
            ->assertJsonValidationErrors('fingerprint');
        $this->assertSame($saved, $block->fresh()->configuracion['document']);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.diseno_actualizado', 'recurso_id' => $block->id]);
    }

    public function test_a_part_can_be_saved_without_a_name(): void
    {
        $template = $this->template();
        $block = $template->fields()->where('clave', 'objetivo_general')->firstOrFail()->block;
        $design = $block->configuracion['document'];
        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);

        $this->patchJson($url, [
            'document' => $this->document(),
            'fingerprint' => SaveTemplateDocument::fingerprint($block),
            'properties' => [[
                'key' => 'objetivo_general',
                'label' => '',
                'help' => null,
            ]],
        ])->assertSuccessful();

        $this->assertSame('', $block->fields()->where('clave', 'objetivo_general')->firstOrFail()->etiqueta);
    }

    public function test_admin_creates_a_selection_field_and_uses_it_as_a_conditional_mark(): void
    {
        $template = $this->template();
        $block = $template->fields()->where('clave', 'objetivo_general')->firstOrFail()->block;
        $field = fn (?string $choice) => ['type' => 'field', 'attrs' => [
            'key' => 'campo_requiere_adaptacion',
            'label' => '¿Requiere adaptación?',
            'kind' => 'seleccion_unica',
            'options' => ['Sí', 'No'],
            'choice' => $choice,
        ]];
        $cell = fn (array $content) => ['type' => 'tableCell', 'content' => [[
            'type' => 'paragraph', 'content' => $content,
        ]]];
        $document = ['type' => 'doc', 'content' => [[
            'type' => 'table', 'content' => [[
                'type' => 'tableRow', 'content' => [
                    $cell([$field(null)]),
                    $cell([$field('Sí')]),
                ],
            ]],
        ]]];

        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);
        $this->patch($url, [
            'document' => $document,
            'fingerprint' => SaveTemplateDocument::fingerprint($block),
        ])->assertSuccessful();

        $created = $block->fields()->where('clave', 'campo_requiere_adaptacion')->firstOrFail();
        $this->assertSame('seleccion_unica', $created->tipo);
        $this->assertSame(['Sí', 'No'], $created->opciones);
        $this->assertTrue($created->editable_docente);

        $resolved = TemplateDocumentResolver::resolve(
            $block->fresh()->configuracion['document'],
            [['key' => $created->clave, 'value' => 'Sí']],
            [],
        );
        $this->assertSame(['Sí', 'X'], array_column(TemplateDocument::nodes($resolved, 'text'), 'text'));

        $document['content'][0]['content'][0]['content'][1]['content'][0]['content'][0]['attrs']['choice'] = 'Quizá';
        $this->patch($url, [
            'document' => $document,
            'fingerprint' => SaveTemplateDocument::fingerprint($block->fresh()),
        ])->assertJsonValidationErrors('document');
    }

    public function test_teacher_and_coordinator_cannot_change_the_design(): void
    {
        $template = $this->template();
        $block = $template->fields()->where('clave', 'objetivo_general')->firstOrFail()->block;
        $design = $block->configuracion['document'];
        foreach (['docente@silabos.test', 'coordinador@silabos.test'] as $email) {
            $user = User::query()->where('correo_electronico', $email)->firstOrFail();
            $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id])
                ->patchJson(route('api.v1.admin.template.blocks.document.update', [$template, $block]), ['document' => $this->document(), 'fingerprint' => SaveTemplateDocument::fingerprint($block)])
                ->assertForbidden();
        }
        $this->assertSame($design, $block->fresh()->configuracion['document']);
    }

    public function test_rejects_unknown_variables_foreign_fields_and_hostile_content_without_writes(): void
    {
        $template = $this->template();
        $block = $template->fields()->where('clave', 'objetivo_general')->firstOrFail()->block;
        $design = $block->configuracion['document'];
        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);
        foreach ([
            ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'secreto_inexistente']]]],
            ['type' => 'paragraph', 'content' => [['type' => 'field', 'attrs' => ['key' => 'descripcion', 'label' => 'Ajeno', 'kind' => 'texto_largo']]]],
            ['type' => 'script', 'text' => 'alert(1)'],
            ['type' => 'paragraph', 'content' => [['type' => 'column', 'attrs' => ['key' => 'fuera_de_tabla', 'label' => 'Inválido', 'kind' => 'texto_largo']]]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texto', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => 'url(https://example.com)']]]]]],
        ] as $node) {
            $this->patch($url, ['document' => ['type' => 'doc', 'content' => [$node]], 'fingerprint' => SaveTemplateDocument::fingerprint($block)])
                ->assertJsonValidationErrors('document');
            $this->assertSame($design, $block->fresh()->configuracion['document']);
        }
    }

    public function test_rejects_non_rectangular_or_cross_boundary_merges(): void
    {
        $doc = $this->document();
        $doc['content'][0]['content'][0]['content'][1]['attrs']['rowspan'] = 3;
        $this->expectException(ValidationException::class);
        TemplateDocument::normalize($doc, array_keys(TemplateVariables::definitions()));
    }

    public function test_complete_vertical_merge_and_dynamic_bullets_remain_valid(): void
    {
        $doc = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['colspan' => 2, 'rowspan' => 2], 'content' => [['type' => 'paragraph']]]]],
            ['type' => 'tableRow'],
        ]]]];
        $this->assertCount(2, TemplateDocument::nodes(TemplateDocument::normalize($doc, []), 'tableRow'));
        $list = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [[
            'type' => 'field', 'attrs' => ['key' => 'items', 'label' => 'Elementos', 'kind' => 'texto_largo', 'listStyle' => 'number'],
        ]]]]];
        $resolved = TemplateDocumentResolver::resolve(TemplateDocument::normalize($list, []), [[
            'key' => 'items', 'value' => "Primero\nSegundo",
        ]], []);
        $this->assertSame('orderedList', $resolved['content'][0]['type']);
        $this->assertCount(2, TemplateDocument::nodes($resolved, 'listItem'));
    }

    public function test_marks_inside_a_list_become_items_of_the_same_list(): void
    {
        $item = fn (array $content): array => ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => $content]]];
        $document = ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [
            $item([['type' => 'text', 'text' => 'Respeto en clase']]),
            $item([['type' => 'field', 'attrs' => ['key' => 'normas', 'label' => 'Normas', 'kind' => 'texto_largo', 'listStyle' => 'bullet']]]),
            $item([['type' => 'variable', 'attrs' => ['id' => 'lista_normas', 'label' => 'x', 'listStyle' => 'bullet']]]),
        ]]]];
        $normalized = TemplateDocument::normalize($document, ['lista_normas']);
        $this->assertSame('bullet', TemplateDocument::nodes($normalized, 'variable')[0]['attrs']['listStyle']);

        $resolved = TemplateDocumentResolver::resolve($normalized, [['key' => 'normas', 'value' => "Puntualidad\nHonestidad"]], [
            'lista_normas' => "1. Trabajo en equipo\n2. Ética profesional",
        ], null);

        $this->assertCount(1, TemplateDocument::nodes($resolved, 'bulletList'));
        $this->assertSame(
            ['Respeto en clase', 'Puntualidad', 'Honestidad', 'Trabajo en equipo', 'Ética profesional'],
            array_map(fn (array $node): string => $node['text'], TemplateDocument::nodes($resolved, 'text')),
        );
        $this->expectException(ValidationException::class);
        TemplateDocument::normalize(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'variable', 'attrs' => ['id' => 'lista_normas', 'listStyle' => 'tabla']],
        ]]]], ['lista_normas']);
    }

    public function test_new_parts_are_free_text_or_template_content(): void
    {
        $template = $this->template();
        $section = $template->sections()->firstWhere('clave', 'etica');
        $admin = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($admin)->withSession(['active_role_assignment_id' => $admin->roleAssignments()->firstOrFail()->id]);
        $this->postJson(route('api.v1.admin.template.fields.store', $template), [
            'section_id' => $section->id, 'key' => 'normas_propias', 'label' => '', 'content_type' => 'free_text',
        ])->assertSuccessful();
        $this->postJson(route('api.v1.admin.template.fields.store', $template), [
            'section_id' => $section->id, 'key' => 'normas_fijas', 'label' => 'Normas', 'content_type' => 'template',
        ])->assertSuccessful();

        $free = $template->fresh()->blocks()->firstWhere('clave', 'normas_propias');
        $field = $free->fields->sole();
        $this->assertSame(['text', 'texto_libre', $section->titulo], [$free->configuredContentType(), $field->tipo, $field->etiqueta]);
        $this->assertSame('texto_libre', TemplateDocument::nodes($free->configuracion['document'], 'field')[0]['attrs']['kind']);
        $blank = $template->fresh()->blocks()->firstWhere('clave', 'normas_fijas');
        $this->assertCount(0, $blank->fields);
        $this->assertSame([['type' => 'paragraph', 'content' => []]], $blank->configuracion['document']['content']);

        $this->postJson(route('api.v1.admin.template.sections.store', $template), [
            'title' => 'Normas de clase', 'key' => 'normas', 'fields' => [
                ['key' => 'normas_docente', 'label' => '', 'content_type' => 'free_text'],
                ['key' => 'normas_plantilla', 'label' => '', 'content_type' => 'template'],
            ],
        ])->assertSuccessful();
        $this->assertSame('Normas de clase', $template->fields()->where('clave', 'normas_docente')->sole()->etiqueta);
        $this->assertFalse($template->fields()->where('clave', 'normas_plantilla')->exists());
        $this->assertSame(['free_text', 'template'], array_column(
            $this->getJson(route('api.v1.admin.template.show', $template))->json('blockTypes'), 'value',
        ));
    }

    public function test_conditions_are_defined_on_the_sheet_marks(): void
    {
        $template = $this->template();
        $section = $template->sections()->firstWhere('clave', 'etica');
        $this->postJson(route('api.v1.admin.template.fields.store', $template), [
            'section_id' => $section->id, 'key' => 'practicas', 'label' => 'Prácticas', 'content_type' => 'template',
        ])->assertSuccessful();
        $block = $template->fresh()->blocks()->firstWhere('clave', 'practicas');
        $sheet = fn (?array $showWhen): array => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => '¿Hay prácticas? '],
            ['type' => 'field', 'attrs' => ['key' => 'hay_practicas', 'label' => 'Hay prácticas', 'kind' => 'seleccion_unica', 'options' => ['Sí', 'No']]],
            ['type' => 'text', 'text' => ' Detalle: '],
            ['type' => 'field', 'attrs' => ['key' => 'detalle_practicas', 'label' => 'Detalle', 'kind' => 'texto_largo', 'showWhen' => $showWhen]],
        ]]]];
        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);

        $this->patchJson($url, ['document' => $sheet(['key' => 'hay_practicas', 'value' => 'Sí']), 'fingerprint' => SaveTemplateDocument::fingerprint($block)])
            ->assertSuccessful();
        $detail = $template->fields()->where('clave', 'detalle_practicas')->sole();
        $this->assertSame(['key' => 'hay_practicas', 'value' => 'Sí'], $detail->mostrar_si);
        $this->assertNull($template->fields()->where('clave', 'hay_practicas')->sole()->mostrar_si);

        $shown = collect($this->getJson(route('api.v1.admin.template.show', $template))->json('template.sections'))
            ->flatMap(fn (array $item): array => $item['blocks'])->firstWhere('key', 'practicas');
        $this->assertSame(['key' => 'hay_practicas', 'value' => 'Sí'], TemplateDocument::nodes($shown['document'], 'field')[1]['attrs']['showWhen']);

        $block = $block->fresh();
        $this->patchJson($url, ['document' => $sheet(['key' => 'hay_practicas', 'value' => 'Quizá']), 'fingerprint' => SaveTemplateDocument::fingerprint($block)])
            ->assertJsonValidationErrors(['document' => 'El valor elegido no es una opción de ese campo.']);
        $this->patchJson($url, ['document' => $sheet(null), 'fingerprint' => SaveTemplateDocument::fingerprint($block)])->assertSuccessful();
        $this->assertNull($detail->fresh()->mostrar_si);
    }

    public function test_cell_style_uses_global_typography_and_ignores_legacy_cell_size(): void
    {
        $document = $this->document();
        $cell = TemplateDocument::normalize(
            $document,
            array_keys(TemplateVariables::definitions()),
        )['content'][0]['content'][0]['content'][1];

        $this->assertSame('#E7E6E6', $cell['attrs']['backgroundColor']);
        $this->assertSame('#FFFFFF', $cell['attrs']['textColor']);
        $this->assertSame('center', $cell['attrs']['textAlign']);
        $this->assertArrayNotHasKey('fontSize', $cell['attrs']);
        $this->assertTrue($cell['attrs']['bold']);
        $this->assertFalse($cell['attrs']['italic']);
        $this->assertSame('thick', $cell['attrs']['borderStyle']);

        foreach ([
            'backgroundColor' => 'url(https://example.com)',
            'textColor' => 'red',
            'textAlign' => 'diagonal',
            'bold' => 'yes',
            'italic' => 1,
            'borderStyle' => 'javascript',
        ] as $attribute => $invalid) {
            $hostile = $document;
            $hostile['content'][0]['content'][0]['content'][1]['attrs'][$attribute] = $invalid;

            try {
                TemplateDocument::normalize(
                    $hostile,
                    array_keys(TemplateVariables::definitions()),
                );
                $this->fail("El atributo $attribute debía rechazarse.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_text_field_list_format_exports_lines_as_items(): void
    {
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [[
            'type' => 'field', 'attrs' => ['key' => 'resultados', 'label' => 'Resultados esperados', 'kind' => 'texto_largo', 'listStyle' => 'bullet'],
        ]]]]];
        foreach (['bullet' => 'bulletList', 'number' => 'orderedList'] as $style => $type) {
            $doc['content'][0]['content'][0]['attrs']['listStyle'] = $style;
            $resolved = TemplateDocumentResolver::resolve(TemplateDocument::normalize($doc, []), [['key' => 'resultados', 'value' => "Primero\r\n\nSegundo"]], []);
            $this->assertSame($type, $resolved['content'][0]['type']);
            $this->assertSame(['Primero', 'Segundo'], array_column(TemplateDocument::nodes($resolved, 'text'), 'text'));
        }
    }

    public function test_removing_and_restoring_a_field_preserves_its_definition_without_hidden_requirements(): void
    {
        $template = $this->template();
        $field = $template->fields()->where('clave', 'objetivo_general')->firstOrFail();
        $block = $field->block;
        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);
        $this->patch($url, ['title' => 'Objetivo institucional', 'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]], 'fingerprint' => SaveTemplateDocument::fingerprint($block)])
            ->assertSuccessful();
        $this->assertFalse($field->fresh()->editable_docente);
        $this->assertSame('Objetivo institucional', $block->fresh()->titulo);
        $this->patch($url, ['document' => $this->document(), 'fingerprint' => SaveTemplateDocument::fingerprint($block->fresh())])
            ->assertSuccessful();
        $this->assertFalse($field->fresh()->editable_docente);
        $this->assertArrayNotHasKey($field->clave, $block->fresh()->configuracion['detached_fields']);
    }

    public function test_word_and_text_pdf_render_saved_design_and_frozen_values(): void
    {
        $doc = TemplateDocument::normalize($this->document(), array_keys(TemplateVariables::definitions()));
        $fields = [
            ['key' => 'objetivo_general', 'value' => 'Contenido <docente> & aprobado'],
            ['key' => 'respuesta_extra', 'value' => 23],
        ];
        $variables = ['nombre_carrera' => 'Carrera congelada'];
        $resolved = TemplateDocumentResolver::resolve($doc, $fields, $variables);
        $this->assertStringContainsString('Carrera congelada', json_encode($resolved));
        $input = new DocumentRenderInput(
            subject: 'Materia', subjectCode: 'SW-001', academicPeriod: '2026', revisionNumber: 1,
            revisionFingerprint: str_repeat('a', 64),
            generatedAt: '2026-09-06T00:00:00Z', locale: 'es-EC',
            snapshot: [
                'document_mapping' => ['appearance' => [
                    ...TemplateAppearance::defaults(),
                    'table_header_background' => '#548235',
                    'body_alignment' => 'justify',
                ]],
                'template_variables' => $variables,
                'sections' => [['title' => 'Sección', 'blocks' => [['title' => 'Contenido', 'document' => $doc, 'fields' => $fields]]]],
            ],
        );
        $renderer = app(DocumentRenderer::class);
        $bundle = $renderer->render($input);
        $this->assertSame($bundle->docx->fingerprint(), $renderer->render($input)->docx->fingerprint());
        $path = tempnam(sys_get_temp_dir(), 'template-document-test-');
        file_put_contents($path, $bundle->docx->bytes);
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            foreach (['Carrera congelada', 'Contenido &lt;docente&gt; &amp; aprobado', 'w:gridSpan w:val="2"', 'w:vMerge w:val="restart"', 'w:vMerge w:val="continue"', 'w:fill="E7E6E6"', 'w:color w:val="FFFFFF"', 'w:color w:val="CC0000"', 'w:color w:val="000000"', 'w:jc w:val="center"', '<w:tcBorders>', 'w:sz="12"'] as $expected) {
                $this->assertStringContainsString($expected, $xml);
            }
            $this->assertStringNotContainsString('w:sz w:val="18"', $xml);
            $this->assertStringNotContainsString('w:sz w:val="28"', $xml);
            $this->assertStringNotContainsString('w:jc w:val="both"', $xml);
            $this->assertStringNotContainsString('Times New Roman', $xml);
            $this->assertStringNotContainsString('w:fill="548235"', $xml);
        } finally {
            unlink($path);
        }
        $this->assertStringNotContainsString('@nombre_carrera', $bundle->pdf->bytes);
    }

    public function test_design_properties_save_atomically_and_reject_foreign_fields_and_stale_edits(): void
    {
        $template = $this->template();
        $field = $template->fields()->where('clave', 'objetivo_general')->firstOrFail();
        $block = $field->block;
        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);
        $fingerprint = SaveTemplateDocument::fingerprint($block);
        $payload = ['document' => $this->document(), 'fingerprint' => $fingerprint, 'title' => 'Nuevo título',
            'properties' => [[
                'key' => $field->clave,
                'label' => 'Objetivo actualizado',
                'help' => 'Ayuda actualizada.',
            ]]];
        $this->patch($url, [...$payload, 'properties' => [...$payload['properties'], ['key' => 'descripcion', 'label' => 'Campo ajeno', 'help' => 'No debe guardarse.']]])
            ->assertJsonValidationErrors('properties.1.key');
        $this->assertSame($fingerprint, SaveTemplateDocument::fingerprint($block->fresh()));
        $this->patch($url, [...$payload, 'properties' => [['key' => $field->clave, 'label' => 'Objetivo actualizado', 'help' => str_repeat('x', 2001)]]])
            ->assertJsonValidationErrors('properties.0.help');
        $this->assertSame($fingerprint, SaveTemplateDocument::fingerprint($block->fresh()));
        $this->patch($url, $payload)->assertSuccessful();
        $this->assertSame('Nuevo título', $block->fresh()->titulo);
        $this->assertSame('Ayuda actualizada.', $field->fresh()->ayuda);
        $this->assertSame('Objetivo actualizado', $field->fresh()->etiqueta);
        $savedFields = TemplateDocument::nodes($block->fresh()->configuracion['document'], 'field');
        $this->assertSame('Objetivo actualizado', collect($savedFields)->firstWhere('attrs.key', $field->clave)['attrs']['label']);
        $this->assertSame($field->tipo, $field->fresh()->tipo);
        $this->patch($url, [...$payload, 'title' => 'Cambio obsoleto'])->assertJsonValidationErrors('fingerprint');
        $this->assertSame('Nuevo título', $block->fresh()->titulo);
    }

    public function test_removed_fields_are_never_reactivated(): void
    {
        $template = $this->template();
        $field = $template->fields()->where('clave', 'objetivo_general')->firstOrFail();
        $block = $field->block;
        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);
        $payload = ['document' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]], 'fingerprint' => SaveTemplateDocument::fingerprint($block->fresh()),
            'properties' => [['key' => $field->clave, 'label' => $field->etiqueta, 'help' => null]]];
        $this->patch($url, $payload)->assertSuccessful();
        $this->assertFalse($field->fresh()->editable_docente);
        $this->assertFalse($block->fresh()->configuracion['detached_fields'][$field->clave]['editable_docente']);
    }

    public function test_official_format_has_fixed_evaluation_tables_and_signatures_without_flow(): void
    {
        $template = $this->template();
        $blocks = $template->blocks()->keyBy('titulo');

        $this->assertFalse($template->blocks()->contains(fn ($block) => $block->configuredContentType() === 'flow'));
        $this->assertNull($template->fields()->where('clave', 'estado_revision')->first());
        foreach (['Indicadores de evaluación', 'Escala de valoración', 'Revisión y aprobación'] as $title) {
            $block = $blocks->get($title);
            $this->assertSame('fixed', $block->configuredContentType());
            $this->assertCount(0, $block->fields);
            $this->assertSame([], TemplateDocument::nodes($block->configuracion['document'], 'field'));
            $this->assertArrayNotHasKey('tipo', $block->toDocument());
        }
        $text = json_encode($blocks->get('Indicadores de evaluación')->configuracion['document'], JSON_UNESCAPED_UNICODE);
        foreach (['Evaluación parcial', '3.5', '1.75', 'TOTAL', 'Ponderación'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringContainsString('Recuperación', json_encode($blocks->get('Escala de valoración')->configuracion['document'], JSON_UNESCAPED_UNICODE));
        $signatures = array_column(array_column(TemplateDocument::nodes($blocks->get('Revisión y aprobación')->configuracion['document'], 'variable'), 'attrs'), 'id');
        $this->assertEqualsCanonicalizing([
            'firma_docente', 'firma_coordinador', 'firma_bibliotecario',
            'nombre_docente', 'nombre_coordinador', 'fecha_entrega', 'fecha_revision',
        ], $signatures);
    }

    public function test_administration_turns_a_part_to_landscape_and_back(): void
    {
        $template = $this->template();
        $field = $template->fields()->where('clave', 'objetivo_general')->firstOrFail();
        $block = $field->block;
        $url = route('api.v1.admin.template.fields.update', [$template, $field]);
        $payload = ['block_id' => $block->id, 'key' => $field->clave, 'label' => $field->etiqueta, 'content_type' => $block->contentType()];

        $this->patchJson($url, [...$payload, 'page_orientation' => 'landscape'])->assertSuccessful();
        $this->assertSame('landscape', $block->fresh()->pageOrientation());
        $this->getJson(route('api.v1.admin.template.show', $template))
            ->assertJsonFragment(['page_orientation' => 'landscape']);

        $this->patchJson($url, $payload)->assertSuccessful();
        $this->assertSame('landscape', $block->fresh()->pageOrientation());

        $this->patchJson($url, [...$payload, 'page_orientation' => 'sideways'])
            ->assertJsonValidationErrors(['page_orientation' => 'Elija hoja vertical u horizontal.']);
        $this->patchJson($url, [...$payload, 'page_orientation' => null])->assertSuccessful();
        $this->assertNull($block->fresh()->pageOrientation());
    }

    public function test_fixed_text_can_be_landscape_and_is_not_saved_empty(): void
    {
        $template = $this->template();
        $block = $template->blocks()->firstWhere('clave', 'evaluacion_principal');
        $this->assertSame('fixed', $block->configuredContentType());
        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);

        $this->patchJson($url, ['fingerprint' => SaveTemplateDocument::fingerprint($block)])
            ->assertJsonValidationErrors(['document' => 'Escriba el texto fijo antes de guardar.']);
        $this->patchJson($url, [
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
            'fingerprint' => SaveTemplateDocument::fingerprint($block),
        ])->assertJsonValidationErrors(['document' => 'Escriba el texto fijo antes de guardar.']);

        $this->patchJson($url, [
            'document' => $block->configuracion['document'],
            'fingerprint' => SaveTemplateDocument::fingerprint($block),
            'page_orientation' => 'landscape',
        ])->assertSuccessful();
        $this->assertSame('landscape', $block->fresh()->pageOrientation());
    }

    public function test_fields_have_no_optional_flag_or_limits(): void
    {
        $template = $this->template();
        $field = $template->fields()->where('clave', 'objetivo_general')->firstOrFail();
        $block = $field->block;
        $document = TemplateDocumentDefaults::fixedPlaceholder();
        $document['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'field', 'attrs' => ['key' => 'objetivo_general', 'label' => 'Objetivo general', 'kind' => 'texto_largo']]]];

        $this->patchJson(route('api.v1.admin.template.blocks.document.update', [$template, $block]), [
            'document' => $document,
            'fingerprint' => SaveTemplateDocument::fingerprint($block->fresh()),
            'properties' => [['key' => 'objetivo_general', 'label' => 'Objetivo general', 'help' => null, 'rules' => ['required' => false, 'max_length' => 300]]],
        ])->assertJsonValidationErrors('properties.0');
        $this->assertFalse(Schema::hasColumn('definiciones_campo', 'reglas_definicion_campo'));

        $shown = collect($this->getJson(route('api.v1.admin.template.show', $template))->json('template.sections'))
            ->flatMap(fn ($section) => $section['blocks'])->flatMap(fn ($block) => $block['fields'])->firstWhere('key', 'objetivos_especificos');
        $this->assertTrue($shown['required']);
        $this->assertArrayNotHasKey('rules', $shown);
    }

    public function test_administration_sets_show_only_if_on_a_part(): void
    {
        $template = $this->template();
        $type = $template->fields()->where('clave', 'discapacidad_tipo')->firstOrFail();
        $formation = $template->fields()->where('clave', 'formacion_experiencia')->firstOrFail();
        $block = $type->block;
        $this->assertSame(['key' => 'discapacidad_tiene', 'value' => 'Sí'], $type->mostrar_si);
        $this->assertNull($formation->mostrar_si);
        $payload = $this->getJson(route('api.v1.admin.template.show', $template))->json();
        $this->assertContains(['key' => 'discapacidad_tiene', 'value' => 'Sí'], data_get($payload, 'template.sections.*.blocks.*.fields.*.show_when'));

        $this->patchJson(route('api.v1.admin.template.blocks.document.update', [$template, $block]), [
            'document' => $block->fresh()->configuracion['document'],
            'fingerprint' => SaveTemplateDocument::fingerprint($block->fresh()),
            'properties' => [['key' => 'formacion_experiencia', 'label' => $formation->etiqueta, 'help' => '', 'show_when' => null]],
        ])->assertJsonValidationErrors('document');
    }

    private function template(): SyllabusTemplate
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
        $this->postJson(route('api.v1.admin.template.store'))->assertSuccessful();
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();

        return $template->fresh();
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $p = fn ($content) => ['type' => 'paragraph', 'attrs' => ['textAlign' => 'right'], 'content' => $content];
        $cell = fn ($content, $span = 1, $height = 1) => ['type' => 'tableCell', 'attrs' => ['colspan' => $span, 'rowspan' => $height, 'backgroundColor' => '#DBE5F1'], 'content' => [$p($content)]];
        $field = fn ($key, $label, $kind) => ['type' => 'field', 'attrs' => ['key' => $key, 'label' => $label, 'kind' => $kind]];

        $document = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'attrs' => ['rowRole' => 'fixed'], 'content' => [
                $cell([['type' => 'variable', 'attrs' => ['id' => 'nombre_carrera'], 'marks' => [['type' => 'bold'], ['type' => 'italic'], ['type' => 'underline'], ['type' => 'textStyle', 'attrs' => ['fontFamily' => 'Times New Roman', 'fontSize' => '14pt', 'color' => '#CC0000']]]]], 2),
                $cell([$field('objetivo_general', 'Objetivo general', 'texto_largo')], 1, 2),
            ]],
            ['type' => 'tableRow', 'content' => [
                $cell([['type' => 'text', 'text' => 'Texto fijo']]),
                $cell([$field('respuesta_extra', 'Cantidad', 'numero')]),
            ]],
        ]]]];
        $styled = &$document['content'][0]['content'][0]['content'][1]['attrs'];
        $styled = [
            ...$styled,
            'backgroundColor' => '#E7E6E6',
            'textColor' => '#FFFFFF',
            'textAlign' => 'center',
            'fontSize' => '9pt',
            'bold' => true,
            'italic' => false,
            'borderStyle' => 'thick',
        ];

        return $document;
    }
}
