<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Academic\Application\CurricularData;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Application\Actions\SaveTemplateDocument;
use App\Modules\Configuration\Application\CurricularFieldOptions;
use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Application\AcademicContextSnapshot;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Application\RequiredAcademicTemplateData;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;
use ZipArchive;

class CurricularBindingTest extends TestCase
{
    use CreatesSyllabusProcess;
    use RefreshDatabase;

    private Subject $subject;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(SyllabusTemplateSeeder::class);
        $this->subject = Subject::query()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
    }

    public function test_new_concepts_resolve_by_career_and_subject_without_changing_php_and_freeze_their_schema(): void
    {
        $shared = $this->concept('perfil_nuevo', 'texto', 'carrera');
        $skills = $this->concept('habilidades', 'lista');
        $books = $this->concept('bibliografia', 'tabla', 'asignatura', [
            ['key' => 'titulo', 'label' => 'Título'], ['key' => 'autor', 'label' => 'Autor'],
        ]);
        $this->value($shared, 'Perfil común de la carrera.');
        $skillValue = $this->value($skills, ['Pensamiento crítico', 'Comunicación']);
        $this->value($books, [['titulo' => 'Ingeniería de prueba', 'autor' => 'Autor sintético']]);
        $other = $this->subject->replicate();
        $other->codigo_asignatura = 'SW-OTRA';
        $other->save();
        $this->value($skills, ['Solo otra asignatura'], $other);
        $otherCareer = $this->subject->career->replicate();
        $otherCareer->codigo_carrera = 'OTRA';
        $otherCareer->nombre = 'Otra carrera';
        $otherCareer->save();
        $careerValue = $this->value($shared, 'Solo otra carrera.', careerId: $otherCareer->id);
        $context = app(AcademicContextSnapshot::class)->build(ScheduledSubject::query()->firstOrFail());
        $snapshot = ['academic_context' => $context];
        $resolved = TemplateVariables::resolve($snapshot);
        $this->assertSame('Perfil común de la carrera.', $resolved['curricular_perfil_nuevo']);
        $this->assertSame("1. Pensamiento crítico\n2. Comunicación", $resolved['curricular_habilidades']);
        $this->assertSame('Título: Ingeniería de prueba · Autor: Autor sintético', $resolved['curricular_bibliografia']);
        $this->assertSame($this->coordinator->id, data_get($context, 'curricular.evidence.habilidades.confirmed_by'));
        $this->assertSame('Página 3', data_get($context, 'curricular.evidence.habilidades.sources.0.locator'));
        $this->assertSame(['Solo otra asignatura'], app(CurricularData::class)->snapshot($this->subject->carrera_id, $other->id)['values']['habilidades']);
        $this->assertSame($careerValue->valor_curricular, app(CurricularData::class)->snapshot($otherCareer->id, $other->id)['values']['perfil_nuevo']);

        $skillValue->update(['valor_curricular' => ['Catálogo posterior']]);
        $skills->update(['etiqueta_concepto' => 'Etiqueta posterior']);
        $this->concept('concepto_posterior', 'texto');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($resolved, TemplateVariables::resolve($snapshot));
        $this->assertSame('Habilidades', TemplateVariables::definitions($snapshot)['curricular_habilidades']['label']);
        $this->assertArrayNotHasKey('curricular_concepto_posterior', TemplateVariables::resolve($snapshot));
        $this->assertArrayNotHasKey('curricular_habilidades', TemplateVariables::resolve(['academic_context' => ['schema_version' => 4]]));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_admin_can_use_a_new_variable_and_missing_data_blocks_only_templates_using_it(): void
    {
        $concept = $this->concept('contenido_nuevo', 'texto');
        $template = SyllabusTemplate::institutional();
        $block = FieldDefinition::query()->where('clave', 'objetivo_general')->firstOrFail()->block;
        $context = app(AcademicContextSnapshot::class)->build(ScheduledSubject::query()->firstOrFail());
        $this->assertSame([], app(RequiredAcademicTemplateData::class)->issues($context));
        $this->asRole('admin@silabos.test');
        $this->getJson(route('api.v1.admin.template.index'))->assertOk()->assertJsonFragment([
            'key' => 'curricular_contenido_nuevo', 'label' => 'Contenido nuevo', 'type' => 'texto', 'scope' => 'asignatura', 'sample' => 'Contenido nuevo',
        ]);
        $document = $this->document([['type' => 'variable', 'attrs' => ['id' => 'curricular_contenido_nuevo']]]);
        $this->patchJson(route('api.v1.admin.template.blocks.document.update', [$template, $block]), [
            'document' => $document, 'fingerprint' => SaveTemplateDocument::fingerprint($block),
        ])->assertOk();
        $issues = app(RequiredAcademicTemplateData::class)->issues($context);
        $this->assertCount(1, $issues);
        $this->assertSame('dato_curricular_faltante', $issues[0]['code']);
        $this->assertStringContainsString('Coordinación', $issues[0]['message']);
        $this->assertCount(1, app(RequiredAcademicTemplateData::class)->issues(['schema_version' => 4]));
        $this->value($concept, 'Texto confirmado de esta asignatura.');
        $newContext = app(AcademicContextSnapshot::class)->build(ScheduledSubject::query()->firstOrFail());
        $this->assertSame([], app(RequiredAcademicTemplateData::class)->issues($newContext));
        $resolvedDocument = TemplateDocumentResolver::resolve($document, [], TemplateVariables::resolve(['academic_context' => $newContext]), null);
        $this->assertSame('Texto confirmado de esta asignatura.', $resolvedDocument['content'][0]['content'][0]['text']);
    }

    public function test_dynamic_multi_choice_uses_frozen_subject_options_and_rejects_foreign_or_newer_values(): void
    {
        $concept = $this->concept('habilidades', 'lista');
        $record = $this->value($concept, ['Pensamiento crítico', 'Comunicación', 'Colaboración']);
        $field = $this->createQuestion('seleccion_multiple');
        $syllabus = $this->syllabus();
        $this->asRole('docente@silabos.test');
        $this->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertOk();
        $syllabus->refresh();
        $detail = $this->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk()->json('syllabus.sections');
        $fields = collect($detail)->flatMap(fn ($section) => $section['blocks'])->flatMap(fn ($block) => $block['fields']);
        $payload = $fields->firstWhere('key', 'seleccion_habilidades');
        $this->assertSame('curricular_habilidades', $payload['options_source']);
        $this->assertSame(['Pensamiento crítico', 'Comunicación', 'Colaboración'], array_column($payload['options'], 'value'));
        $url = route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]);
        $this->patchJson($url, ['version_bloqueo' => $syllabus->version_bloqueo, 'value' => ['Pensamiento crítico', 'Comunicación', 'Colaboración']])->assertOk();
        $record->update(['valor_curricular' => ['Catálogo posterior']]);
        $syllabus->refresh();
        $this->patchJson($url, ['version_bloqueo' => $syllabus->version_bloqueo, 'value' => ['Solo otra asignatura']])->assertUnprocessable()->assertJsonValidationErrors('value.0');
        $this->patchJson($url, ['version_bloqueo' => $syllabus->version_bloqueo, 'value' => ['Catálogo posterior']])->assertUnprocessable()->assertJsonValidationErrors('value.0');
        $snapshot = app(SyllabusSnapshot::class)->build($syllabus->fresh());
        $fieldSnapshot = collect($snapshot['sections'])->flatMap(fn ($section) => $section['blocks'])->flatMap(fn ($block) => $block['fields'])->firstWhere('key', $field->clave);
        $this->assertSame($payload['options'], $fieldSnapshot['options']);
        $this->assertSame(['Pensamiento crítico', 'Comunicación', 'Colaboración'], $fieldSnapshot['value']);
        $this->assertSame('curricular_habilidades', $fieldSnapshot['options_source']);
        // Un valor alterado fuera del formulario también se rechaza al validar el envío.
        $syllabus->values()->where('definicion_campo_id', $field->id)->update(['valor_campo' => json_encode(['No elegible'])]);
        $this->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertOk()->assertJsonFragment(['code' => 'seleccion_curricular_invalida']);
    }

    public function test_dynamic_single_choice_and_missing_catalog_are_validated_on_the_server(): void
    {
        $concept = $this->concept('habilidades', 'lista');
        $this->value($concept, ['Pensamiento crítico']);
        $field = $this->createQuestion('seleccion_unica');
        $syllabus = $this->syllabus();
        $this->asRole('docente@silabos.test');
        $this->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertOk();
        $syllabus->refresh();
        $url = route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]);
        $this->patchJson($url, ['version_bloqueo' => $syllabus->version_bloqueo, 'value' => 'Pensamiento crítico'])->assertOk();
        $this->patchJson($url, ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => 'Fuera del catálogo'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $context = $syllabus->contexto_academico;
        unset($context['curricular']['values']['habilidades']);
        $syllabus->update(['contexto_academico' => $context]);
        $this->assertSame([], CurricularFieldOptions::options($field, $context));
        $this->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertOk()->assertJsonFragment(['code' => 'catalogo_curricular_faltante']);
    }

    public function test_binding_rejects_non_list_concepts_and_properties_preserve_existing_question_type(): void
    {
        $list = $this->concept('habilidades', 'lista');
        $this->concept('perfil', 'texto', 'carrera');
        $field = $this->createQuestion('seleccion_multiple');
        $block = $field->block;
        $template = SyllabusTemplate::institutional();
        $url = route('api.v1.admin.template.blocks.document.update', [$template, $block]);
        $this->patchJson($url, [
            'document' => $block->configuracion['document'], 'fingerprint' => SaveTemplateDocument::fingerprint($block),
            'properties' => [['key' => $field->clave, 'label' => 'Habilidades', 'help' => null, 'options_source' => 'curricular_perfil']],
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->patchJson($url, [
            'document' => $block->configuracion['document'], 'fingerprint' => SaveTemplateDocument::fingerprint($block),
            'properties' => [['key' => $field->clave, 'options_source' => 'curricular_habilidades', 'label' => 'Habilidades elegibles', 'help' => null]],
        ])->assertOk();
        $this->assertSame('seleccion_multiple', $field->fresh()->tipo);
        $this->assertSame('curricular_'.$list->clave_concepto, CurricularFieldOptions::source($field->fresh()));
    }

    public function test_question_combines_subject_and_career_lists_and_requires_every_bound_catalog(): void
    {
        $subjectConcept = $this->concept('habilidades', 'lista');
        $careerConcept = $this->concept('transversales', 'lista', 'carrera');
        $this->value($subjectConcept, ['Pensamiento crítico', 'Comunicación']);
        $this->value($careerConcept, ['Comunicación', 'Colaboración']);
        $sources = ['curricular_habilidades', 'curricular_transversales'];
        $field = $this->createQuestion('seleccion_multiple', $sources);
        $context = app(AcademicContextSnapshot::class)->build(ScheduledSubject::query()->firstOrFail());
        $this->assertSame($sources, CurricularFieldOptions::sources($field));
        $this->assertNull(CurricularFieldOptions::source($field));
        $this->assertSame(['Pensamiento crítico', 'Comunicación', 'Colaboración'], CurricularFieldOptions::values($field, $context));
        $other = $this->subject->replicate();
        $other->codigo_asignatura = 'SW-UNION-OTRA';
        $other->save();
        $this->value($subjectConcept, ['Solo otra asignatura'], $other);
        $otherContext = ['curricular' => app(CurricularData::class)->snapshot($this->subject->carrera_id, $other->id)];
        $this->assertSame(['Solo otra asignatura', 'Comunicación', 'Colaboración'], CurricularFieldOptions::values($field, $otherContext));
        unset($context['curricular']['values']['transversales']);
        $this->assertSame([], CurricularFieldOptions::values($field, $context));
        $this->assertSame([], CurricularFieldOptions::fromAttributes(['options_sources' => [], 'options_source' => 'curricular_habilidades']));
    }

    public function test_section_and_block_creation_support_curricular_catalogs_and_reject_text_sources(): void
    {
        $this->concept('habilidades', 'lista');
        $this->concept('transversales', 'lista', 'carrera');
        $this->concept('perfil', 'texto');
        $this->asRole('admin@silabos.test');
        $template = SyllabusTemplate::institutional();
        $sources = ['curricular_habilidades', 'curricular_transversales'];
        $this->postJson(route('api.v1.admin.template.sections.store', $template), [
            'title' => 'Selecciones', 'key' => 'selecciones',
            'fields' => [
                ['key' => 'habilidad_uno', 'label' => 'Habilidad uno', 'content_type' => 'multiple_choice', 'options_sources' => $sources],
                ['key' => 'habilidad_dos', 'label' => 'Habilidad dos', 'content_type' => 'multiple_choice', 'options_sources' => $sources],
            ],
        ])->assertOk();
        $one = FieldDefinition::query()->where('clave', 'habilidad_uno')->firstOrFail();
        $this->assertSame($sources, CurricularFieldOptions::sources($one));
        $this->assertSame('seleccion_multiple', $one->tipo);
        $section = $template->fresh()->sections->firstWhere('clave', 'selecciones');
        $this->postJson(route('api.v1.admin.template.fields.store', $template), [
            'section_id' => $section->id, 'key' => 'habilidad_tres', 'label' => 'Habilidad tres',
            'content_type' => 'multiple_choice', 'options_sources' => $sources,
        ])->assertOk();
        $three = FieldDefinition::query()->where('clave', 'habilidad_tres')->firstOrFail();
        $this->assertSame($sources, CurricularFieldOptions::sources($three));
        $this->postJson(route('api.v1.admin.template.fields.store', $template), [
            'section_id' => $section->id, 'key' => 'habilidad_invalida', 'label' => 'Habilidad inválida',
            'content_type' => 'multiple_choice', 'options_sources' => ['curricular_perfil'],
        ])->assertUnprocessable()->assertJsonValidationErrors('options_sources');
        $this->getJson(route('api.v1.admin.template.index'))->assertOk()
            ->assertJsonFragment(['value' => 'multiple_choice', 'label' => 'Selección del catálogo curricular']);
    }

    public function test_word_and_pdf_export_frozen_curricular_lists_tables_and_multiple_selections(): void
    {
        $outcomes = $this->concept('resultados', 'lista');
        $books = $this->concept('bibliografia', 'tabla', 'asignatura', [['key' => 'titulo', 'label' => 'Título']]);
        $this->value($outcomes, ['Analiza A & B.', 'Evalúa el producto.']);
        $this->value($books, [['titulo' => 'Libro confirmado']]);
        $context = app(AcademicContextSnapshot::class)->build(ScheduledSubject::query()->firstOrFail());
        $variables = TemplateVariables::resolve(['academic_context' => $context]);
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'curricular_resultados']]]],
            ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'curricular_bibliografia']]]],
            ['type' => 'paragraph', 'content' => [['type' => 'field', 'attrs' => ['key' => 'elegidas']]]],
        ]];
        $input = new DocumentRenderInput(
            subject: 'Materia sintética', subjectCode: 'SW-PRUEBA', academicPeriod: '2026', revisionNumber: 1,
            revisionFingerprint: str_repeat('a', 64), generatedAt: '2026-09-27T00:00:00Z', locale: 'es-EC',
            snapshot: ['template_variables' => $variables, 'academic_context' => $context, 'sections' => [[
                'title' => 'Currículo', 'blocks' => [[
                    'title' => 'Datos confirmados', 'document' => $document,
                    'fields' => [['key' => 'elegidas', 'type' => 'seleccion_multiple', 'value' => ['Comunicación', 'Colaboración'], 'rows' => []]],
                ]],
            ]]],
        );
        // La exportación conserva su fotografía aunque se corrijan posteriormente los datos vivos.
        CurricularValue::query()->where('concepto_id', $outcomes->id)->firstOrFail()->update(['valor_curricular' => ['Texto posterior']]);
        $bundle = app(DocumentRenderer::class)->render($input);
        $path = tempnam(sys_get_temp_dir(), 'curricular-export-');
        file_put_contents($path, $bundle->docx->bytes);
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            foreach (['1. Analiza A &amp; B.', '2. Evalúa el producto.', 'Título: Libro confirmado', 'Comunicación', 'Colaboración'] as $text) {
                $this->assertStringContainsString($text, $xml);
            }
            $this->assertStringNotContainsString('Texto posterior', $xml);
            $this->assertStringNotContainsString('curricular_resultados', $xml);
            foreach (['1. Analiza A & B.', 'Libro confirmado', '2. Evalúa el producto.', 'Comunicación'] as $text) {
                $this->assertStringContainsString(mb_convert_encoding($text, 'Windows-1252', 'UTF-8'), $bundle->pdf->bytes);
            }
            $this->assertStringNotContainsString('Texto posterior', $bundle->pdf->bytes);
        } finally {
            unlink($path);
        }
    }

    public function test_legacy_outcome_variables_prefer_confirmed_import_with_matching_evidence_and_preserve_old_snapshots(): void
    {
        $concept = $this->concept('resultados_aprendizaje', 'lista');
        $value = $this->value($concept, ['Analiza el producto.', 'Evalúa su calidad.']);
        $value->update(['evidencia_curricular' => [[
            'source_id' => 'fuente-sintetica', 'source_name' => 'Proyecto documental', 'locator' => 'Página 141', 'quote' => 'Analiza el producto. Evalúa su calidad.',
        ]]]);
        $context = app(AcademicContextSnapshot::class)->build(ScheduledSubject::query()->firstOrFail());
        $context['subject']['learning_outcomes'] = ['source_name' => 'Registro anterior', 'source_locator' => 'Página 12', 'outcomes' => [['text' => 'Resultado manual anterior.']]];
        $legacy = $context;
        unset($legacy['curricular']);
        $snapshot = ['academic_context' => $context];
        $variables = TemplateVariables::resolve($snapshot);
        $this->assertSame("1. Analiza el producto.\n2. Evalúa su calidad.", $variables['resultados_aprendizaje_asignatura']);
        $this->assertSame($variables['curricular_resultados_aprendizaje'], $variables['resultados_aprendizaje_asignatura']);
        $this->assertSame('Proyecto documental', $variables['fuente_resultados_aprendizaje']);
        $this->assertSame('Proyecto documental — Página 141', $variables['ubicacion_resultados_aprendizaje']);
        $block = FieldDefinition::query()->where('clave', 'objetivo_general')->firstOrFail()->block;
        $block->update(['configuracion' => ['content_type' => 'fixed', 'document' => $this->document([
            ['type' => 'variable', 'attrs' => ['id' => 'resultados_aprendizaje_asignatura']],
        ])]]);
        $withoutManual = $context;
        unset($withoutManual['subject']['learning_outcomes']);
        $this->assertSame([], app(RequiredAcademicTemplateData::class)->issues($withoutManual));
        $value->update(['valor_curricular' => ['Corrección documental posterior.']]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($variables, TemplateVariables::resolve($snapshot));
        $oldVariables = TemplateVariables::resolve(['academic_context' => $legacy]);
        $this->assertSame('1. Resultado manual anterior.', $oldVariables['resultados_aprendizaje_asignatura']);
        $this->assertSame('Registro anterior', $oldVariables['fuente_resultados_aprendizaje']);
        $this->assertSame('Página 12', $oldVariables['ubicacion_resultados_aprendizaje']);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    /** @param list<array{key: string, label: string}>|null $columns */
    private function concept(string $key, string $type, string $scope = 'asignatura', ?array $columns = null): CurricularConcept
    {
        return CurricularConcept::query()->create([
            'clave_concepto' => $key, 'etiqueta_concepto' => ucfirst(str_replace('_', ' ', $key)),
            'tipo_concepto' => $type, 'ambito_concepto' => $scope, 'columnas_concepto' => $columns,
        ]);
    }

    private function value(CurricularConcept $concept, mixed $value, ?Subject $subject = null, ?string $careerId = null): CurricularValue
    {
        return CurricularValue::query()->create([
            'concepto_id' => $concept->id, 'carrera_id' => $careerId ?? $this->subject->carrera_id,
            'asignatura_id' => $concept->ambito_concepto === 'carrera' ? null : ($subject ?? $this->subject)->id,
            'valor_curricular' => $value, 'evidencia_curricular' => [['source_id' => 'prueba', 'locator' => 'Página 3', 'quote' => 'Extracto sintético']],
            'confirmado_por' => $this->coordinator->id, 'confirmado_en' => now(),
        ]);
    }

    public function test_project_data_is_declared_from_the_sheet_and_reused_by_name(): void
    {
        $this->asRole('admin@silabos.test');
        $template = SyllabusTemplate::institutional();
        $this->postJson(route('api.v1.admin.template.project-data.store', $template), [
            'label' => 'Bibliografía básica', 'type' => 'lista', 'scope' => 'asignatura',
        ])->assertCreated()->assertJsonPath('variable', [
            'key' => 'curricular_bibliografia_basica', 'label' => 'Bibliografía básica', 'sample' => 'Bibliografía básica',
            'type' => 'lista', 'scope' => 'asignatura',
        ]);
        $this->assertTrue(AuditEvent::query()->where('accion', 'curriculo.concepto_declarado')->exists());

        // El mismo nombre con la misma forma se reutiliza; con otra forma se rechaza.
        $this->postJson(route('api.v1.admin.template.project-data.store', $template), [
            'label' => 'Bibliografía  básica', 'type' => 'lista', 'scope' => 'asignatura',
        ])->assertCreated()->assertJsonPath('variable.key', 'curricular_bibliografia_basica');
        $this->assertSame(1, CurricularConcept::query()->count());
        $this->postJson(route('api.v1.admin.template.project-data.store', $template), [
            'label' => 'Bibliografía básica', 'type' => 'texto', 'scope' => 'asignatura',
        ])->assertUnprocessable()->assertJsonValidationErrors('label');
        $this->postJson(route('api.v1.admin.template.project-data.store', $template), [
            'label' => '¿?', 'type' => 'lista', 'scope' => 'asignatura',
        ])->assertUnprocessable()->assertJsonValidationErrors('label');

        // «Sale sola de los documentos» ya no es una forma de parte (I-155).
        $section = $template->sections->firstWhere('clave', 'bibliografia');
        $this->postJson(route('api.v1.admin.template.fields.store', $template), [
            'section_id' => $section->id, 'key' => 'bibliografia_automatica', 'label' => 'Bibliografía', 'content_type' => 'automatic',
        ])->assertUnprocessable()->assertJsonValidationErrors('content_type');
        $this->getJson(route('api.v1.admin.template.index'))->assertOk()
            ->assertJsonFragment(['key' => 'curricular_bibliografia_basica', 'label' => 'Bibliografía básica', 'type' => 'lista', 'scope' => 'asignatura']);

        $this->asRole('coordinador@silabos.test');
        $this->postJson(route('api.v1.admin.template.project-data.store', $template), [
            'label' => 'Otro dato', 'type' => 'lista', 'scope' => 'asignatura',
        ])->assertForbidden();
    }

    private function asRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
    }

    /** @param list<string>|null $sources */
    private function createQuestion(string $kind, ?array $sources = null): FieldDefinition
    {
        $this->asRole('admin@silabos.test');
        $template = SyllabusTemplate::institutional();
        $block = FieldDefinition::query()->where('clave', 'objetivo_general')->firstOrFail()->block;
        $this->patchJson(route('api.v1.admin.template.blocks.document.update', [$template, $block]), [
            'fingerprint' => SaveTemplateDocument::fingerprint($block),
            'document' => $this->document([['type' => 'field', 'attrs' => [
                'key' => 'seleccion_habilidades', 'label' => 'Habilidades', 'kind' => $kind,
                ...($sources === null ? ['options_source' => 'curricular_habilidades'] : ['options_sources' => $sources]), 'options' => null,
            ]]]),
        ])->assertOk();

        return FieldDefinition::query()->where('clave', 'seleccion_habilidades')->firstOrFail();
    }

    /** @param list<array<string, mixed>> $content
     * @return array<string, mixed>
     */
    private function document(array $content): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $content]]];
    }

    private function syllabus(): Syllabus
    {
        $process = $this->openSyllabusProcess(SyllabusTemplate::institutional()->id);
        $convocation = Convocation::query()->create([
            'proceso_id' => $process->id, 'carrera_id' => $this->subject->carrera_id, 'estado' => Convocation::STATE_OPEN,
        ]);
        app(GenerateConvocationSyllabi::class)->execute($convocation);

        return Syllabus::query()->firstOrFail();
    }
}
