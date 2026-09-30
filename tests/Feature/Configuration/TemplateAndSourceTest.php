<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public function test_administrator_creates_an_empty_template_and_can_apply_the_official_format(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.store'), [
                'nombre' => 'Plantilla Software',
                'description' => 'Prototipo estructurado para validación.',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Plantilla vacía creada.');

        $template = SyllabusTemplate::query()->firstOrFail();
        $this->assertTrue(Schema::hasTable('plantilla_silabo'));
        $this->assertFalse(Schema::hasTable('plantillas_silabo'));
        $this->assertFalse(Schema::hasTable('componentes_plantilla'));
        $this->assertCount(0, $template->sections());
        $this->assertCount(0, $template->blocks());
        $this->assertCount(0, $template->fields()->get());

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.format', $template))
            ->assertSuccessful()
            ->assertJsonPath('message', 'Formato oficial aplicado.');

        $template->refresh();
        $this->assertCount(12, $template->sections());
        $this->assertCount(21, $template->fields()->get(), 'Incluye la ficha, las tablas del docente y los campos del PDF; el contenido fijo no tiene campos.');
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'plantilla.formateada',
            'recurso_id' => $template->id,
        ]);

        $evaluation = $template->sections()->where('clave', 'evaluacion')->firstOrFail();
        $blocks = $evaluation->blocks()->orderBy('posicion');
        $this->assertSame(['Indicadores de evaluación', 'Escala de valoración', 'Recuperación y aprobación'], $blocks->pluck('titulo')->all());
        $this->assertSame(['fixed', 'fixed', 'text'], $blocks->map->configuredContentType()->all());
        $this->assertSame('texto_libre', $template->fields()->where('clave', 'recuperacion')->sole()->tipo);
        $this->assertArrayNotHasKey('table', $blocks->first()->configuracion);
        $this->assertNull($template->fields()->where('clave', 'indicadores_evaluacion')->first());
        $this->assertDatabaseCount('valores_campo', 0);
        $this->assertFalse(Schema::hasTable('filas_repetibles'));
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
            ->postJson(route('api.v1.admin.template.format', $template))
            ->assertJsonValidationErrors('template');

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.template.show', $template))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->has('template.sections', 12)
                ->where('template.id', $template->id)
                ->where('processLock', null));
    }

    public function test_administrator_creates_a_section_with_free_text_and_template_content(): void
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
                        'content_type' => 'free_text',
                    ],
                    ['key' => 'nota_resultados', 'label' => 'Nota', 'content_type' => 'template'],
                ],
            ])
            ->assertSuccessful();

        $section = $template->fresh()->sections()->where('clave', 'bloque_resultados')->firstOrFail();
        $blocks = $section->blocks()->orderBy('posicion');
        $this->assertSame(2, $section->posicion);
        $this->assertSame(['Resumen', 'Nota'], $blocks->pluck('titulo')->all());
        $this->assertSame(['text', 'text'], $blocks->map->configuredContentType()->all());
        $this->assertSame('texto_libre', $blocks[0]->fields->sole()->tipo);
        $this->assertCount(0, $blocks[1]->fields);
        $this->assertFalse(Schema::hasColumn('definiciones_campo', 'obligatorio'));
        $this->assertSame('Sintetice los resultados esperados.', $blocks[0]->fields->firstOrFail()->ayuda);
        $this->assertFalse(Schema::hasColumn('definiciones_campo', 'ia_coordinacion_configurable'));
        $this->assertFalse(Schema::hasTable('preferencias_ia_carrera'));

        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->actingAsAdministrator()
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.admin.template.index'))
            ->assertOk()
            ->assertJsonPath('template.titleBlock.text', TemplateTitleBlock::DEFAULT_TEXT)
            ->assertJsonPath('blockTypes', [
                ['value' => 'free_text', 'label' => 'Texto libre'],
                ['value' => 'template', 'label' => 'Personalizado'],
            ])
            ->assertJsonMissing(['ai_coordinator_configurable' => true]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.sections.store', $template), [
                'title' => 'Bloque inválido',
                'key' => 'bloque_invalido',
                'fields' => [['key' => 'resumen_resultados', 'label' => 'Clave repetida', 'content_type' => 'free_text']],
            ])
            ->assertJsonValidationErrors('fields.0.key');
        $this->assertNull($template->fresh()->sections()->firstWhere('clave', 'bloque_invalido'));

        foreach (['text', 'table', 'bulleted_list', 'numbered_list', 'boolean', 'fixed', 'multiple_choice'] as $legacy) {
            $this->actingAsAdministrator()
                ->postJson(route('api.v1.admin.template.fields.store', $template), [
                    'section_id' => $section->id, 'key' => "parte_{$legacy}", 'label' => 'Parte', 'content_type' => $legacy,
                ])
                ->assertJsonValidationErrors('content_type');
        }
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

    public function test_database_rejects_a_field_for_a_block_absent_from_the_template_document(): void
    {
        $template = $this->createTemplate();

        $this->expectException(QueryException::class);
        FieldDefinition::query()->create([
            'plantilla_id' => $template->id,
            'campo_plantilla_id' => (string) Str::uuid(),
            'clave' => 'campo_huerfano',
            'etiqueta' => 'Campo huérfano',
            'tipo' => 'texto_largo',
            'heredado' => false,
            'editable_docente' => true,
            'posicion' => 1,
        ]);
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
        $template = $this->createTemplate();
        $field = $template->fields()->where('clave', 'objetivo_general')->firstOrFail();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.fields.update', [$template, $field]), [
                'block_id' => $field->campo_plantilla_id,
                'key' => $field->clave,
                'label' => 'Etiqueta corregida',
                'content_type' => 'text',
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $this->assertSame('Etiqueta corregida', $field->fresh()->etiqueta);
        $this->assertSame(1, SyllabusTemplate::query()->count());

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.fields.update', [$template, $field]), [
                'block_id' => $field->campo_plantilla_id,
                'key' => $field->clave,
                'label' => 'Etiqueta corregida',
                'content_type' => 'fixed',
            ])
            ->assertJsonValidationErrors(['content_type' => 'La forma de un campo no se cambia. Elimínelo y cree uno nuevo con la forma que necesita.']);
        $this->assertSame('text', $field->fresh()->block->contentType());
    }

    public function test_field_properties_are_help_and_ai_only_and_every_field_stays_required(): void
    {
        $template = $this->createTemplate();
        $this->assertFalse(Schema::hasColumn('definiciones_campo', 'obligatorio'));

        $field = $template->fields()->where('clave', 'objetivo_general')->firstOrFail();
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.fields.update', [$template, $field]), [
                'block_id' => $field->campo_plantilla_id,
                'key' => $field->clave,
                'label' => $field->etiqueta,
                'content_type' => 'text',
                'help' => 'Redáctelo en infinitivo.',
                'required' => 0,
                'teacher_editable' => 0,
                'inherited' => 1,
                'master_source' => 'invento',
            ])
            ->assertSuccessful()
            ->assertSuccessful();
        $field->refresh();
        $this->assertSame('Redáctelo en infinitivo.', $field->ayuda);
        $this->assertTrue($field->editable_docente);
        $this->assertFalse($field->heredado);
        $this->assertNull($field->origen_maestro);

        $identification = $template->fields()->where('clave', 'asignatura')->firstOrFail();
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.fields.update', [$template, $identification]), [
                'block_id' => $identification->campo_plantilla_id,
                'key' => $identification->clave,
                'label' => $identification->etiqueta,
                'content_type' => 'institutional',
                'help' => 'Se llena sola desde la malla y la programación de asignatura.',
            ])
            ->assertSuccessful()
            ->assertSuccessful();
        $identification->refresh();
        $this->assertSame('Se llena sola desde la malla y la programación de asignatura.', $identification->ayuda);
        $this->assertTrue($identification->heredado);
        $this->assertSame('asignaturas', $identification->origen_maestro);
    }

    public function test_a_part_may_have_no_name_but_a_section_still_needs_one(): void
    {
        $version = $this->createTemplate();
        $section = $version->sections()->where('clave', 'objetivos')->firstOrFail();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.fields.store', $version), [
                'section_id' => $section->id,
                'key' => 'parte_sin_nombre',
                'label' => '',
                'content_type' => 'free_text',
            ])
            ->assertSuccessful();

        $field = FieldDefinition::query()->where('plantilla_id', $version->id)->where('clave', 'parte_sin_nombre')->firstOrFail();
        $this->assertSame($section->titulo, $field->etiqueta);
        $this->assertSame('', $field->block?->titulo);
        $this->assertSame($section->titulo, $field->displayName());

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.template.fields.update', [$version, $field]), [
                'block_id' => $field->campo_plantilla_id,
                'key' => 'parte_sin_nombre',
                'label' => '   ',
                'content_type' => 'text',
            ])
            ->assertSuccessful();
        $this->assertSame('', $field->fresh()->etiqueta);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.sections.store', $version), [
                'title' => '',
                'key' => 'seccion_sin_titulo',
                'fields' => [['key' => 'parte_de_seccion', 'label' => null, 'content_type' => 'free_text']],
            ])
            ->assertJsonValidationErrors('title')
            ->assertJsonMissingValidationErrors('fields.0.label');

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.sections.store', $version), [
                'title' => 'Sección con parte sin nombre',
                'key' => 'seccion_parte_sin_nombre',
                'fields' => [['key' => 'parte_de_seccion', 'label' => null, 'content_type' => 'free_text']],
            ])
            ->assertSuccessful();
        $this->assertSame('Sección con parte sin nombre', FieldDefinition::query()->where('plantilla_id', $version->id)->where('clave', 'parte_de_seccion')->value('etiqueta_definicion_campo'));
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
                'content_type' => 'free_text',
                'position' => 1,
            ])
            ->assertSuccessful();

        $block = $version->fresh()->sections()->whereKey($section->id)->firstOrFail()
            ->blocks()->where('titulo', 'Estrategias de aprendizaje')->firstOrFail();
        $this->assertSame('narrativa', $block->tipo);
        $this->assertSame('text', $block->configuracion['content_type']);
        $this->assertSame('texto_libre', $block->fields()->firstOrFail()->tipo);
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
                'content_type' => 'free_text',
            ])
            ->assertSuccessful();

        $section = $section->fresh();
        $second = $section->blocks()->where('clave', 'objetivos_especificos_reorden')->firstOrFail();
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

        $this->assertNull($version->fresh()->block($second->id));
        $this->assertDatabaseMissing('definiciones_campo', ['campo_plantilla_id' => $second->id]);
    }

    public function test_table_structure_form_no_longer_exists(): void
    {
        $version = $this->createTemplate();
        $block = $version->sections()->where('clave', 'descripcion')->firstOrFail()->blocks()->firstOrFail();

        $this->actingAsAdministrator()
            ->patchJson("/api/v1/admin/template/{$version->id}/blocks/{$block->id}/table", ['columns' => []])
            ->assertNotFound();
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
                'first_field_content_type' => 'free_text',
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

        $this->assertNull($version->fresh()->section($created->id));
        foreach ($created->blocks()->pluck('id') as $blockId) {
            $this->assertDatabaseMissing('definiciones_campo', ['campo_plantilla_id' => $blockId]);
        }
    }

    public function test_source_management_endpoints_are_retired_for_every_role(): void
    {
        foreach ([$this->administrator, $this->coordinator] as $user) {
            $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
            $this->getJson('/api/v1/coordination/sources')->assertNotFound();
            $this->postJson('/api/v1/coordination/sources', ['nombre' => 'Retirada'])->assertNotFound();
            $id = (string) Str::uuid();
            foreach (['get', 'patch', 'put', 'delete'] as $method) {
                $this->{$method.'Json'}('/api/v1/coordination/sources/'.$id)->assertNotFound();
            }
            $this->putJson('/api/v1/coordination/sources/'.$id.'/content', ['content' => 'Retirada'])->assertNotFound();
            $this->postJson('/api/v1/coordination/sources/'.$id.'/pdf')->assertNotFound();
        }
        $this->assertDatabaseCount('documentos_importacion', 0);
    }

    private function createTemplate(): SyllabusTemplate
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla verificable']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.format', $template))
            ->assertSuccessful();

        return $template->fresh();
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
