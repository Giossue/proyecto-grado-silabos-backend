<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Application\Actions\SaveTemplateDocument;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Tests\TestCase;

class TemplateAndSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
    }

    public function test_administrator_creates_baseline_template_with_twelve_areas(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.store'), [
                'nombre' => 'Plantilla Software',
                'description' => 'Prototipo estructurado para validación.',
            ])
            ->assertSuccessful();

        $template = SyllabusTemplate::query()->firstOrFail();
        $this->assertFalse(Schema::hasColumn('plantillas_silabo', 'es_institucional'));
        $this->assertCount(12, $template->sections()->get());
        $this->assertCount(25, $template->fields()->get(), 'Incluye la ficha y las dos tablas de evaluación del PDF de referencia.');

        $evaluation = $template->sections()->where('clave', 'evaluacion')->firstOrFail();
        $blocks = $evaluation->blocks()->orderBy('posicion')->get();
        $this->assertSame(['Indicadores de evaluación', 'Escala de valoración', 'Recuperación y aprobación'], $blocks->pluck('titulo')->all());
        $indicators = $blocks->first()->configuracion['table'];
        $this->assertSame(['indicador', 'primer_parcial', 'ponderacion_primer_parcial', 'segundo_parcial', 'ponderacion_segundo_parcial'], array_column($indicators['columns'], 'key'));
        $this->assertFalse($indicators['totals']['enabled'], 'No se incorpora una fórmula o ponderación pendiente de validación.');
        $this->assertSame([false], array_values(array_unique(array_column($indicators['columns'], 'sum'))));
        $indicatorField = $template->fields()->where('clave', 'indicadores_evaluacion')->firstOrFail();
        $this->assertTrue($indicatorField->editable_docente);
        $this->assertFalse($indicatorField->heredado);
        $this->assertSame('repetible', $indicatorField->tipo);
        $this->assertDatabaseCount('valores_campo', 0);
        $this->assertDatabaseCount('filas_repetibles', 0);
        $this->assertEquals(
            TemplateAppearance::defaults(),
            $template->mapeo_documento['appearance'],
        );
        $this->assertEquals(
            TemplateTitleBlock::defaults(),
            $template->mapeo_documento['title_block'],
        );

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.template.show', $template))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->has('template.sections', 12)
                ->where('template.appearance.font_family', 'Arial')
                ->where('template.titleBlock.text', TemplateTitleBlock::DEFAULT_TEXT)
                ->where('logos.institution', fn (string $url): bool => str_contains($url, '/logos/institucion'))
                ->where('logos.faculty', fn (string $url): bool => str_contains($url, '/images/silabo/facultad.jpeg'))
                ->has('appearanceOptions.colors', count(TemplateAppearance::COLORS))
                ->where('processLock', null));

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.template.show', $template))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->has('template.sections', 12)
                ->where('template.id', $template->id)
                ->where('processLock', null));
    }

    public function test_administrator_creates_a_block_as_a_container_of_typed_fields(): void
    {
        $template = $this->createTemplate();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.sections.store', $template), [
                'title' => 'Resultados y evidencias',
                'key' => 'bloque_resultados',
                'position' => 2,
                'fields' => [
                    [
                        'key' => 'resumen_resultados',
                        'label' => 'Resumen',
                        'help' => 'Sintetice los resultados esperados.',
                        'content_type' => 'text',
                        'ai_enabled' => true,
                        'ai_coordinator_configurable' => false,
                    ],
                    ['key' => 'matriz_evidencias', 'label' => 'Matriz de evidencias', 'content_type' => 'table'],
                    ['key' => 'acciones_mejora', 'label' => 'Acciones de mejora', 'content_type' => 'numbered_list'],
                    ['key' => 'requiere_adaptacion', 'label' => 'Requiere adaptación', 'content_type' => 'boolean'],
                    [
                        'key' => 'modalidades_evaluacion',
                        'label' => 'Modalidades de evaluación',
                        'content_type' => 'multiple_choice',
                        'options' => [
                            ['value' => 'proyecto', 'label' => 'Proyecto'],
                            ['value' => 'examen', 'label' => 'Examen'],
                        ],
                    ],
                ],
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $section = $template->fresh()->sections()
            ->where('clave', 'bloque_resultados')
            ->firstOrFail();
        $blocks = $section->blocks()->with('fields')->orderBy('posicion')->get();

        $this->assertSame(2, $section->posicion);
        $this->assertSame(
            ['Resumen', 'Matriz de evidencias', 'Acciones de mejora', 'Requiere adaptación', 'Modalidades de evaluación'],
            $blocks->pluck('titulo')->all(),
        );
        $this->assertSame(
            ['text', 'table', 'numbered_list', 'boolean', 'multiple_choice'],
            $blocks->pluck('configuracion')->map(fn (array $configuration): string => $configuration['content_type'])->all(),
        );
        $this->assertSame(
            ['resumen_resultados', 'matriz_evidencias', 'acciones_mejora', 'requiere_adaptacion', 'modalidades_evaluacion'],
            $blocks->pluck('clave')->all(),
        );
        $this->assertSame('texto', $blocks[1]->configuracion['table']['columns'][0]['key']);
        $this->assertFalse(Schema::hasColumn('definiciones_campo', 'obligatorio'));
        $this->assertSame('booleano', $blocks[3]->fields->firstOrFail()->tipo);
        $this->assertSame('seleccion_multiple', $blocks[4]->fields->firstOrFail()->tipo);
        $this->assertSame('proyecto', $blocks[4]->fields->firstOrFail()->opciones[0]['value']);
        $this->assertSame('Sintetice los resultados esperados.', $blocks[0]->fields->firstOrFail()->ayuda);
        $this->assertTrue($blocks[0]->fields->firstOrFail()->ia_habilitada);
        $this->assertFalse($blocks[0]->fields->firstOrFail()->ia_coordinacion_configurable);

        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->actingAsAdministrator()
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.admin.template.index'))
            ->assertOk()
            ->assertJsonPath('template.titleBlock.text', TemplateTitleBlock::DEFAULT_TEXT)
            ->assertJsonFragment(['value' => 'boolean', 'label' => 'Sí / No'])
            ->assertJsonFragment(['value' => 'multiple_choice', 'label' => 'Selección múltiple'])
            ->assertJsonFragment([
                'key' => 'modalidades_evaluacion',
                'content_type' => 'multiple_choice',
                'ai_enabled' => false,
                'ai_coordinator_configurable' => true,
            ]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.sections.store', $template), [
                'title' => 'Bloque inválido',
                'key' => 'bloque_invalido',
                'fields' => [
                    ['key' => 'resumen_resultados', 'label' => 'Clave repetida', 'content_type' => 'text'],
                ],
            ])
            ->assertJsonValidationErrors('fields.0.key');

        $this->assertDatabaseMissing('componentes_plantilla', ['clave_componente_plantilla' => 'bloque_invalido']);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.fields.store', $template), [
                'section_id' => $section->id,
                'key' => 'seleccion_sin_opciones',
                'label' => 'Selección sin opciones',
                'content_type' => 'multiple_choice',
            ])
            ->assertJsonValidationErrors('options');
    }

    public function test_only_administrator_updates_controlled_template_appearance(): void
    {
        $template = $this->createTemplate();
        $appearance = [
            ...TemplateAppearance::defaults(),
            'font_family' => 'Georgia',
            'body_font_size' => 12,
            'title_font_size' => 18,
            'section_font_size' => 14,
            'field_font_size' => 12,
            'text_color' => '#1F4E78',
            'accent_color' => '#C00000',
            'table_header_background' => '#548235',
            'table_header_color' => '#FFFFFF',
            'margin_cm' => 2.0,
            'orientation' => 'landscape',
            'title_italic' => true,
            'section_alignment' => 'center',
            'body_alignment' => 'justify',
        ];

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.appearance.update', $template), $appearance)
            ->assertSuccessful()
            ->assertSuccessful();

        $expected = [
            ...TemplateAppearance::defaults(),
            'body_font_size' => 12,
            'title_font_size' => 18,
            'section_font_size' => 14,
            'field_font_size' => 12,
            'margin_cm' => 2.0,
            'orientation' => 'landscape',
        ];
        $this->assertEquals($expected, $template->fresh()->mapeo_documento['appearance']);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'plantilla.apariencia_actualizada',
            'recurso_id' => $template->id,
        ]);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.appearance.update', $template), [
                ...$appearance,
                'font_family' => 'Comic Sans MS',
                'accent_color' => 'url(https://example.com)',
            ])
            ->assertJsonValidationErrors(['font_family', 'accent_color']);
        $this->assertEquals($expected, $template->fresh()->mapeo_documento['appearance']);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.admin.template.appearance.update', $template), TemplateAppearance::defaults())
            ->assertForbidden();
    }

    public function test_title_is_a_single_persisted_block_always_projected_before_sections(): void
    {
        $this->assertSame(
            TemplateTitleBlock::defaults(),
            TemplateTitleBlock::fromMapping(['title_block' => 'formato anterior']),
        );
        $template = $this->createTemplate();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.title.update', $template), [
                'title' => 'PROGRAMA INSTITUCIONAL DE ASIGNATURA',
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $template->refresh();
        $this->assertSame(
            ['text' => 'PROGRAMA INSTITUCIONAL DE ASIGNATURA'],
            $template->mapeo_documento['title_block'],
        );
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'plantilla.titulo_actualizado',
            'recurso_id' => $template->id,
        ]);

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.template.show', $template))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where('template.titleBlock.text', 'PROGRAMA INSTITUCIONAL DE ASIGNATURA')
                ->has('template.sections', 12));

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.title.update', $template), ['title' => ''])
            ->assertJsonValidationErrors('title');

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.admin.template.title.update', $template), [
                'title' => 'Cambio no autorizado',
            ])
            ->assertForbidden();

        $this->assertSame(
            'PROGRAMA INSTITUCIONAL DE ASIGNATURA',
            $template->fresh()->mapeo_documento['title_block']['text'],
        );
    }

    public function test_administrator_can_only_create_one_institutional_template(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla institucional'])
            ->assertSuccessful();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Otra plantilla'])
            ->assertJsonValidationErrors('template');

        $this->assertSame(1, SyllabusTemplate::query()->count());
    }

    public function test_database_rejects_a_second_template(): void
    {
        SyllabusTemplate::query()->create(['nombre' => 'Plantilla oficial', 'activo' => true]);

        $this->expectException(QueryException::class);
        SyllabusTemplate::query()->create(['nombre' => 'Plantilla duplicada', 'activo' => true]);
    }

    public function test_non_administrator_cannot_manage_templates(): void
    {
        $template = $this->createTemplate();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.admin.template.index'))
            ->assertForbidden();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.admin.template.show', $template))
            ->assertForbidden();
    }

    public function test_template_is_edited_in_place_without_publishing(): void
    {
        // I-32: no hay versiones ni publicación. La plantilla se corrige en el sitio y
        // cada revisión enviada conserva su propia copia.
        $template = $this->createTemplate();
        $field = $template->fields()->firstOrFail();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.fields.update', [$template, $field]), [
                'block_id' => $field->bloque_plantilla_id,
                'key' => $field->clave,
                'label' => 'Etiqueta corregida',
                'content_type' => 'text',
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $this->assertSame('Etiqueta corregida', $field->fresh()->etiqueta);
        $this->assertSame(1, SyllabusTemplate::query()->count());
    }

    /** I-33 (ajuste 2026-09-03): todo campo es obligatorio; solo se decide ayuda e IA. */
    public function test_field_properties_are_help_and_ai_only_and_every_field_stays_required(): void
    {
        $template = $this->createTemplate();
        $this->assertFalse(Schema::hasColumn('definiciones_campo', 'obligatorio'));

        $field = $template->fields()->where('clave', 'objetivo_general')->firstOrFail();
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.fields.update', [$template, $field]), [
                'block_id' => $field->bloque_plantilla_id,
                'key' => $field->clave,
                'label' => $field->etiqueta,
                'content_type' => 'text',
                'help' => 'Redáctelo en infinitivo.',
                'ai_enabled' => 1,
                'ai_coordinator_configurable' => 0,
                'required' => 0,
                'teacher_editable' => 0,
                'inherited' => 1,
                'master_source' => 'invento',
            ])
            ->assertSuccessful()
            ->assertSuccessful();
        $field->refresh();
        $this->assertSame('Redáctelo en infinitivo.', $field->ayuda);
        $this->assertTrue($field->ia_habilitada);
        $this->assertFalse($field->ia_coordinacion_configurable);
        $this->assertTrue($field->editable_docente);
        $this->assertFalse($field->heredado);
        $this->assertNull($field->origen_maestro);

        // La ficha de identificación es un bloque fijo: admite ayuda y conserva su origen.
        $identification = $template->fields()->where('clave', 'asignatura')->firstOrFail();
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.fields.update', [$template, $identification]), [
                'block_id' => $identification->bloque_plantilla_id,
                'key' => $identification->clave,
                'label' => $identification->etiqueta,
                'content_type' => 'institutional',
                'help' => 'Se llena sola desde la malla y la programación de asignatura.',
                'ai_enabled' => 1,
                'ai_coordinator_configurable' => 1,
            ])
            ->assertSuccessful()
            ->assertSuccessful();
        $identification->refresh();
        $this->assertSame('Se llena sola desde la malla y la programación de asignatura.', $identification->ayuda);
        $this->assertTrue($identification->heredado);
        $this->assertSame('asignaturas', $identification->origen_maestro);
        $this->assertFalse($identification->ia_habilitada);
        $this->assertFalse($identification->ia_coordinacion_configurable);
    }

    public function test_template_blocks_use_document_content_types(): void
    {
        $version = $this->createTemplate();
        $section = $version->sections()->where('clave', 'objetivos')->firstOrFail();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.fields.store', $version), [
                'section_id' => $section->id,
                'key' => 'estrategias_aprendizaje',
                'label' => 'Estrategias de aprendizaje',
                'content_type' => 'bulleted_list',
                'teacher_editable' => true,
                'position' => 1,
            ])
            ->assertSuccessful();

        $block = $version->fresh()->sections()->whereKey($section->id)->firstOrFail()
            ->blocks()->where('titulo', 'Estrategias de aprendizaje')->firstOrFail();
        $this->assertSame('repetible', $block->tipo);
        $this->assertSame('bulleted_list', $block->configuracion['content_type']);
        $this->assertSame('repetible', $block->fields()->firstOrFail()->tipo);
        $this->assertSame(1, $block->posicion);
    }

    public function test_administrator_reorders_and_removes_draft_blocks(): void
    {
        $version = $this->createTemplate();
        $section = $version->sections()->where('clave', 'objetivos')->firstOrFail();
        $first = $section->blocks()->firstOrFail();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.fields.store', $version), [
                'section_id' => $section->id,
                'key' => 'objetivos_especificos_reorden',
                'label' => 'Objetivos específicos',
                'content_type' => 'numbered_list',
            ])
            ->assertSuccessful();

        $second = $section->blocks()->where('titulo', 'Objetivos específicos')->firstOrFail();
        $remainingBlockIds = $section->blocks()
            ->whereNotIn('id', [$first->id, $second->id])
            ->orderBy('posicion')
            ->pluck('id')
            ->all();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.blocks.reorder', $version), [
                'section_id' => $section->id,
                'block_ids' => [$second->id, $first->id, ...$remainingBlockIds],
            ])
            ->assertSuccessful();

        $this->assertSame(1, $second->fresh()->posicion);
        $this->assertSame(2, $first->fresh()->posicion);

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.template.blocks.destroy', ['template' => $version, 'block' => $second]))
            ->assertSuccessful();

        $this->assertDatabaseMissing('componentes_plantilla', ['id' => $second->id]);
        $this->assertDatabaseMissing('definiciones_campo', ['componente_plantilla_id' => $second->id]);
    }

    public function test_administrator_designs_a_complex_table_on_a_block(): void
    {
        $version = $this->createTemplate();
        // La descripción nace como texto: al pasar a tabla expone la tabla mínima.
        $section = $version->sections()->where('clave', 'descripcion')->firstOrFail();
        $block = $section->blocks()->firstOrFail();
        $field = $block->fields()->firstOrFail();
        $sectionIndex = $section->posicion - 1;

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.fields.update', ['template' => $version, 'field' => $field]), [
                'block_id' => $block->id,
                'key' => $field->clave,
                'label' => $field->etiqueta,
                'content_type' => 'table',
            ])
            ->assertSuccessful();
        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.template.show', $version))
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where("template.sections.$sectionIndex.blocks.0.table.columns.0.key", 'texto'));

        $layout = [
            'columns' => [
                ['key' => 'contenidos', 'label' => 'Contenidos temáticos', 'type' => 'text', 'width' => 320],
                ['key' => 'acd', 'label' => 'ACD', 'type' => 'number', 'group' => 'docencia', 'band' => 'horas', 'sum' => false],
                ['key' => 'ape', 'label' => 'APE', 'type' => 'number', 'group' => 'estudiante', 'band' => 'horas'],
                ['key' => 'aa', 'label' => 'AA', 'type' => 'number', 'group' => 'estudiante', 'band' => 'horas'],
            ],
            'groups' => [['key' => 'docencia', 'label' => 'Docencia'], ['key' => 'estudiante', 'label' => 'Estudiante']],
            'bands' => [['key' => 'horas', 'label' => 'Horas por semana']],
            'header_fields' => [['key' => 'nombre', 'label' => 'Nombre de la unidad']],
            'totals' => ['enabled' => true, 'label' => 'Total, horas'],
            'repeat' => ['enabled' => true, 'label' => 'Unidad'],
        ];
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.blocks.table.update', ['template' => $version, 'block' => $block]), [
                ...$layout,
                'fingerprint' => SaveTemplateDocument::fingerprint($block->fresh()),
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $saved = $block->fresh()->configuracion['table'];
        $this->assertSame('table', $block->fresh()->configuracion['content_type']);
        $this->assertSame(['contenidos', 'acd', 'ape', 'aa'], array_column($saved['columns'], 'key'));
        $this->assertSame(320, $saved['columns'][0]['width']);
        $this->assertFalse($saved['columns'][1]['sum']);
        $this->assertTrue($saved['repeat']['enabled']);
        $this->assertDatabaseHas('eventos_auditoria', ['accion_evento_auditoria' => 'plantilla.tabla_actualizada', 'recurso_id' => $block->id]);

        // Un grupo partido por otra columna no es una cabecera posible.
        $broken = $layout;
        $broken['columns'][1]['group'] = 'estudiante';
        $broken['columns'][2]['group'] = 'docencia';
        $broken['columns'][3]['group'] = 'estudiante';
        $broken['fingerprint'] = SaveTemplateDocument::fingerprint($block->fresh());
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.blocks.table.update', ['template' => $version, 'block' => $block]), $broken)
            ->assertJsonValidationErrors('groups');

        // Un campo de texto no acepta esquema de tabla.
        $textBlock = $version->sections()->where('clave', 'habilidades')->firstOrFail()->blocks()->firstOrFail();
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.blocks.table.update', ['template' => $version, 'block' => $textBlock]), [
                ...$layout,
                'fingerprint' => SaveTemplateDocument::fingerprint($textBlock),
            ])
            ->assertJsonValidationErrors('table');
    }

    public function test_administrator_manages_template_blocks_separately_from_their_fields(): void
    {
        $version = $this->createTemplate();
        $first = $version->sections()->firstOrFail();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.sections.store', $version), [
                'title' => 'Recursos y materiales',
                'key' => 'recursos_materiales',
                'first_field_label' => 'Recursos principales',
                'first_field_key' => 'recursos_principales',
                'first_field_content_type' => 'table',
                'position' => 2,
            ])
            ->assertSuccessful();

        $created = $version->fresh()->sections()
            ->where('titulo', 'Recursos y materiales')
            ->firstOrFail();
        $this->assertSame(2, $created->posicion);
        $this->assertSame('Recursos principales', $created->blocks()->firstOrFail()->fields()->firstOrFail()->etiqueta);

        $sectionIds = $version->fresh()->sections()->pluck('id')->all();
        $orderedIds = [$created->id, ...array_values(array_filter($sectionIds, fn (string $id): bool => $id !== $created->id))];

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.sections.reorder', $version), ['section_ids' => $orderedIds])
            ->assertSuccessful();

        $this->assertSame(1, $created->fresh()->posicion);
        $this->assertSame(2, $first->fresh()->posicion);

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.template.sections.destroy', ['template' => $version, 'section' => $created]))
            ->assertSuccessful();

        $this->assertDatabaseMissing('componentes_plantilla', ['id' => $created->id]);
        $this->assertDatabaseMissing('componentes_plantilla', ['componente_padre_id' => $created->id]);
    }

    public function test_coordinator_creates_and_edits_source_document(): void
    {
        $source = $this->createSourceAsCoordinator('Perfil de egreso');
        $this->assertSame('Documento de referencia.', $source->descripcion);
        $this->assertFalse(Schema::hasColumn('fuentes_academicas', 'notas_internas'));

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.sources.update', $source), [
                'nombre' => 'Perfil de egreso 2026',
                'description' => 'Versión socializada con docentes.',
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $updated = $source->fresh();
        $this->assertSame('Perfil de egreso 2026', $updated->nombre);

        $this->actingAsCoordinator()
            ->putJson(route('api.v1.coordination.sources.content.update', $source), [
                'content' => "## Resultado\n\nDiseña software seguro.",
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $this->assertSame("## Resultado\n\nDiseña software seguro.", $source->fresh()->contenido);
        $this->assertDatabaseHas('fragmentos_fuente_academica', [
            'fuente_academica_id' => $source->id,
            'titulo_fragmento' => 'Resultado',
            'posicion_fragmento' => 0,
            'contenido_fragmento' => 'Diseña software seguro.',
        ]);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'fuente.contenido_actualizado',
            'recurso_id' => $source->id,
        ]);

        $this->actingAsCoordinator()

            ->getJson(route('api.v1.coordination.sources.show', $source))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('source.name', 'Perfil de egreso 2026')
                ->where('source.content', "## Resultado\n\nDiseña software seguro."));
    }

    public function test_source_name_is_unique_per_career(): void
    {
        $this->createSourceAsCoordinator('Reglamento académico');

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.sources.store'), ['nombre' => 'Reglamento académico'])
            ->assertJsonValidationErrors('nombre');

        $this->assertSame(1, AcademicSource::query()->where('nombre', 'Reglamento académico')->count());
    }

    public function test_coordinator_can_remove_a_source_without_erasing_its_history(): void
    {
        $source = $this->createSourceAsCoordinator('Fuente para retirar');

        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.sources.destroy', $source))
            ->assertOk()
            ->assertJsonPath('message', 'Fuente eliminada.');

        $this->assertFalse($source->fresh()->activo);
        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.sources.index'))
            ->assertOk()
            ->assertJsonCount(0, 'sources');
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'fuente.eliminada',
            'recurso_id' => $source->id,
        ]);
    }

    public function test_administrator_cannot_access_sources(): void
    {
        $source = $this->createSourceAsCoordinator('Fuente de coordinación');

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.coordination.sources.index'))
            ->assertForbidden();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.coordination.sources.store'), ['nombre' => 'Fuente administrativa'])
            ->assertForbidden();

        $this->actingAsAdministrator()
            ->putJson(route('api.v1.coordination.sources.content.update', $source), ['content' => 'Edición indebida'])
            ->assertForbidden();

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.coordination.sources.destroy', $source))
            ->assertForbidden();
    }

    public function test_coordinator_cannot_open_source_from_another_career(): void
    {
        $faculty = Faculty::query()->firstOrFail();
        $otherCareer = Career::query()->create([
            'facultad_id' => $faculty->id,
            'codigo_carrera' => 'REDES',
            'nombre' => 'Redes',
            'codigo_malla' => 'PLAN-REDES',
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);
        $source = AcademicSource::query()->create([
            'carrera_id' => $otherCareer->id,
            'nombre' => 'Fuente fuera de alcance',
            'activo' => true,
        ]);

        $this->actingAsCoordinator()

            ->getJson(route('api.v1.coordination.sources.show', $source))
            ->assertForbidden();

        $this->actingAsCoordinator()
            ->putJson(route('api.v1.coordination.sources.content.update', $source), ['content' => 'Edición fuera de alcance'])
            ->assertForbidden();
    }

    private function createTemplate(): SyllabusTemplate
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla verificable']);

        return SyllabusTemplate::query()->firstOrFail();
    }

    private function createSourceAsCoordinator(string $name): AcademicSource
    {
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.sources.store'), [
                'nombre' => $name,
                'description' => 'Documento de referencia.',
            ])
            ->assertSuccessful();

        return AcademicSource::query()->where('nombre', $name)->firstOrFail();
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)
            ->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

        return $this;
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)
            ->withSession(['active_role_assignment_id' => $this->coordinatorContext->id]);

        return $this;
    }
}
