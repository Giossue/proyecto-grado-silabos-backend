<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use App\Modules\Documents\Infrastructure\Rendering\WordFieldRenderer;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\Actions\OpenConvocation;
use App\Modules\Syllabus\Application\CoordinatorConvocationReadiness;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\MakesTransparentPng;
use Tests\TestCase;

class ConvocationAndDraftTest extends TestCase
{
    use CreatesSyllabusProcess;
    use MakesTransparentPng;
    use RefreshDatabase;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    private User $teacher;

    private RoleAssignment $teacherContext;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seed(DatabaseSeeder::class);

        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacherContext = $this->teacher->roleAssignments()->firstOrFail();
    }

    public function test_coordinator_opens_convocation_and_generates_scoped_syllabus_with_master_values(): void
    {
        $convocation = $this->createPreparedConvocation();

        $this->openPrepared($convocation);

        $convocation->refresh();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->assertSame('abierta', $convocation->estado);
        $this->assertSame('sin_iniciar', $syllabus->estado);
        $this->assertSame('Arquitectura de Software', data_get($syllabus->contexto_academico, 'subject.name'));
        $this->assertSame(8, data_get($syllabus->contexto_academico, 'curriculum.cycle_count'));
        $this->assertArrayNotHasKey('code', data_get($syllabus->contexto_academico, 'curriculum'));
        $this->assertNotNull($syllabus->parallel);
        $this->assertCount(1, $syllabus->currentTeachers()->get());
        // La identificación es un dato automático: no guarda valor, se resuelve al enviar.
        $automatic = FieldDefinition::query()->where('clave', 'asignatura')->firstOrFail();
        $this->assertSame('automatico', $automatic->tipo);
        $this->assertNull($syllabus->values()->where('definicion_campo_id', $automatic->id)->firstOrFail()->valor);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'convocatoria.abierta',
            'recurso_id' => $convocation->id,
        ]);

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.convocations.show', $convocation))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('convocation.counts.total', 1)
                ->has('convocation.syllabi', 1));

        $this->assertOpeningFails($convocation, 'Solo una convocatoria en preparación puede abrirse.');
        $this->assertDatabaseCount('silabos', 1);
    }

    public function test_new_syllabus_inherits_profile_and_only_bibliography_is_teacher_editable(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $description = FieldDefinition::query()->where('clave', 'descripcion')->firstOrFail();
        $bibliography = FieldDefinition::query()->where('clave', 'bibliografia')->firstOrFail();
        $this->assertSame('ficha', $description->origen_maestro);
        $this->assertTrue($description->heredado);
        $this->assertFalse($description->editable_docente);
        $this->assertTrue($bibliography->heredado);
        $this->assertTrue($bibliography->editable_docente);
        $this->assertSame('Ficha de prueba: descripcion', FreeText::plainText($syllabus->values()->where('definicion_campo_id', $description->id)->firstOrFail()->valor));
        $this->assertStringContainsString('Ficha de prueba: bibliografia', FreeText::plainText($syllabus->values()->where('definicion_campo_id', $bibliography->id)->firstOrFail()->valor));

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $description]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => $this->freeTextValue('Cambio no autorizado'),
        ])->assertUnprocessable()->assertJsonValidationErrors('field');
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $bibliography]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => $this->freeTextValue('Libro añadido por el docente'),
        ])->assertOk();
        $this->assertSame('Libro añadido por el docente', FreeText::plainText($syllabus->values()->where('definicion_campo_id', $bibliography->id)->firstOrFail()->valor));
    }

    public function test_fixed_fields_are_frozen_in_the_snapshot_and_the_teacher_cannot_edit_them(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $fixed = FieldDefinition::query()->where('clave', 'indicadores_evaluacion')->firstOrFail();
        $this->assertSame('fijo', $fixed->tipo);
        $this->assertNull($syllabus->values()->where('definicion_campo_id', $fixed->id)->first()?->valor);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $fixed]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => $this->freeTextValue('Cambio no autorizado'),
        ])->assertUnprocessable();

        $snapshot = app(SyllabusSnapshot::class)->build($syllabus->fresh());
        $field = collect($snapshot['sections'])->flatMap(fn ($section) => $section['fields'])->firstWhere('key', 'indicadores_evaluacion');
        $this->assertSame('fijo', $field['type']);
        $this->assertSame($fixed->typeConfig()['content'], $field['value']);
        app(SyllabusSnapshot::class)->restore($syllabus->fresh(), $snapshot);
        $this->assertNull($syllabus->values()->where('definicion_campo_id', $fixed->id)->first()?->valor);
    }

    public function test_profile_changes_do_not_rewrite_an_existing_syllabus(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $original = app(SyllabusSnapshot::class)->build($syllabus);
        FieldValue::query()->where('asignatura_id', $syllabus->parallel->asignatura_id)->update(['valor' => FreeText::fromText('Texto nuevo')]);
        $later = app(SyllabusSnapshot::class)->build($syllabus->fresh());
        $valueOf = fn (array $snapshot): mixed => collect($snapshot['sections'])->flatMap(fn ($section) => $section['blocks'])
            ->flatMap(fn ($block) => $block['fields'])->firstWhere('key', 'descripcion')['value'];
        $this->assertSame($valueOf($original), $valueOf($later));
    }

    public function test_snapshot_v7_carries_typed_fields_and_round_trips(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $snapshot = app(SyllabusSnapshot::class)->build($syllabus);
        $this->assertSame(7, $snapshot['schema_version']);
        $fields = collect($snapshot['sections'])->flatMap(fn ($section) => $section['fields']);
        $this->assertNotEmpty($fields);
        $this->assertSame([], $fields->filter(fn ($field) => ! in_array($field['type'], ['texto', 'ficha', 'tabla', 'automatico', 'fijo'], true))->all());
        $description = $fields->firstWhere('key', 'descripcion');
        $this->assertSame('ficha', $description['type']);
        $this->assertSame(
            $syllabus->values()->where('definicion_campo_id', $description['definition_id'])->firstOrFail()->valor,
            $description['value'],
        );

        $definition = FieldDefinition::query()->findOrFail($description['definition_id']);
        $syllabus->values()->where('definicion_campo_id', $definition->id)->update(['valor' => FreeText::fromText('Cambio posterior')]);
        app(SyllabusSnapshot::class)->restore($syllabus, $snapshot);
        $this->assertSame($description['value'], $syllabus->values()->where('definicion_campo_id', $definition->id)->firstOrFail()->valor);
    }

    public function test_the_official_template_syllabus_inherits_profile_fields_and_exports_word_by_type(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $description = FieldDefinition::query()->where('clave', 'descripcion')->firstOrFail();
        $this->assertSame('Ficha de prueba: descripcion', FreeText::plainText($syllabus->values()->where('definicion_campo_id', $description->id)->firstOrFail()->valor));

        $snapshot = app(SyllabusSnapshot::class)->build($syllabus);
        foreach ($snapshot['sections'] as $section) {
            $this->assertTrue(WordFieldRenderer::usesTypes($section), "La sección {$section['key']} debe pintarse por tipo.");
        }
        $docx = app(DocumentRenderer::class)->render(new DocumentRenderInput(
            subject: 'Materia', subjectCode: 'X-1', academicPeriod: 'P', revisionNumber: 1, revisionFingerprint: str_repeat('a', 64),
            generatedAt: '2026-09-07T20:00:00-05:00', locale: 'es-EC', snapshot: $snapshot,
        ))->docx;
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-oficial-');
        file_put_contents($temporary, $docx->bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporary);

        $text = strip_tags($xml);
        $this->assertStringContainsString('Ficha de prueba: descripcion', $text);
        $this->assertStringContainsString('Contenidos temáticos', $text);
        $this->assertStringContainsString('Revisión y aprobación', $text);
        foreach (['Indicadores', 'Primer parcial', 'Evaluación parcial', 'Escala cualitativa', 'Excelente', 'Conducta y comportamiento ético', 'Elaborado por', 'Aprobado por', 'Cargo', 'Firma'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_a_stored_v7_revision_renders_without_reading_live_data(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $snapshot = app(SyllabusSnapshot::class)->build($syllabus);
        $description = collect($snapshot['sections'])->flatMap(fn ($section) => $section['fields'])->firstWhere('key', 'descripcion');
        $frozen = FreeText::plainText($description['value']);
        FieldValue::query()->where('definicion_campo_id', $description['definition_id'])->update(['valor' => FreeText::fromText('Dato vivo posterior')]);
        $snapshot['sections'] = array_map(function (array $section) use ($description): array {
            $section['fields'] = array_map(function (array $field) use ($description): array {
                return $field['key'] === 'descripcion' ? [...$field, 'value' => $description['value']] : $field;
            }, $section['fields']);

            return $section;
        }, $snapshot['sections']);

        DB::enableQueryLog();
        $docx = app(DocumentRenderer::class)->render(new DocumentRenderInput(
            subject: 'Materia', subjectCode: 'X-1', academicPeriod: 'P', revisionNumber: 1, revisionFingerprint: str_repeat('a', 64),
            generatedAt: '2026-09-07T20:00:00-05:00', locale: 'es-EC', snapshot: $snapshot,
        ))->docx;
        $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'valores_campo') || str_contains($query['query'], 'definiciones_campo'));
        $this->assertSame([], array_values($queries));
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-hist-');
        file_put_contents($temporary, $docx->bytes);
        $zip = new \ZipArchive;
        $zip->open($temporary);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporary);
        $this->assertStringContainsString(htmlspecialchars($frozen, ENT_XML1), $xml);
        $this->assertStringNotContainsString('Dato vivo posterior', $xml);
    }

    public function test_missing_profile_reports_a_coordinator_action(): void
    {
        $convocation = $this->createPreparedConvocation();
        FieldValue::query()->whereNotNull('asignatura_id')->delete();
        $this->openPrepared($convocation);
        $syllabus = Syllabus::query()->firstOrFail();
        $description = FieldDefinition::query()->where('clave', 'descripcion')->firstOrFail();
        $this->assertNull($syllabus->values()->where('definicion_campo_id', $description->id)->firstOrFail()->valor);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $result = $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertOk();
        $this->assertContains([
            'field_id' => $description->id,
            'code' => 'maestro_obligatorio_faltante',
            'severity' => 'error',
            'message' => 'La asignatura no tiene ficha: pídala al coordinador.',
        ], $result->json('validation.results'));
    }

    public function test_empty_profile_bibliography_is_optional_for_validation(): void
    {
        $convocation = $this->createPreparedConvocation();
        $bibliographyIds = FieldDefinition::query()->whereIn('clave', ['bibliografia', 'bibliografia_complementaria'])->pluck('id')->all();
        FieldValue::query()->whereNotNull('asignatura_id')->whereIn('definicion_campo_id', $bibliographyIds)->delete();
        $this->openPrepared($convocation);
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $issues = $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertOk()->json('validation.results');
        $this->assertSame([], array_values(array_intersect($bibliographyIds, array_column($issues, 'field_id'))));
    }

    public function test_faculty_logo_is_required_before_opening_the_convocation(): void
    {
        $convocation = $this->createPreparedConvocation(withFacultyLogo: false);

        $this->assertOpeningFails($convocation, 'Administración debe cargar el logo de la facultad antes de que Coordinación abra la convocatoria.');

        $this->assertSame('preparacion', $convocation->fresh()->estado);
        $this->assertDatabaseCount('silabos', 0);
    }

    public function test_existing_syllabus_keeps_its_academic_snapshot_when_the_structure_changes(): void
    {
        $existing = $this->openConvocationAndGetSyllabus();
        $originalName = $existing->academicSubjectName();
        $existing->subject->update(['nombre' => 'Nombre nuevo en la malla']);

        $this->assertSame($originalName, $existing->fresh()->academicSubjectName());
    }

    public function test_explicit_per_parallel_mode_generates_one_syllabus_for_each_parallel(): void
    {
        $scheduledSubject = Parallel::query()->firstOrFail();
        $parallel = Parallel::query()->create([
            'periodo_academico_id' => $scheduledSubject->periodo_academico_id, 'asignatura_id' => $scheduledSubject->asignatura_id,
            'codigo' => 'B',
            'jornada' => 'matutina',
        ]);
        TeacherAssignment::query()->create([
            'usuario_id' => $this->teacher->id,
            'paralelo_id' => $parallel->id,
            'activo' => true,
        ]);
        $convocation = $this->createPreparedConvocation();

        $this->openPrepared($convocation);

        $this->assertSame(2, Syllabus::query()->count());
        $this->assertSame(2, Syllabus::query()->distinct()->count('paralelo_id'));
    }

    public function test_resuming_generates_syllabi_only_for_parallels_created_during_the_pause(): void
    {
        $convocation = $this->createPreparedConvocation();
        $this->openPrepared($convocation);
        $original = Syllabus::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();
        $base = Parallel::query()->firstOrFail();
        $parallel = Parallel::query()->create([
            'periodo_academico_id' => $base->periodo_academico_id,
            'asignatura_id' => $base->asignatura_id,
            'codigo' => 'B',
            'jornada' => 'matutina',
        ]);
        TeacherAssignment::query()->create([
            'usuario_id' => $this->teacher->id,
            'paralelo_id' => $parallel->id,
            'activo' => true,
        ]);

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))
            ->assertSuccessful();

        $this->assertSame('abierta', $convocation->fresh()->estado);
        $this->assertSame(2, Syllabus::query()->count());
        $this->assertTrue(Syllabus::query()->whereKey($original->id)->exists());
        $this->assertTrue(Syllabus::query()->where('paralelo_id', $parallel->id)->exists());

        $this->assertSame(0, app(GenerateConvocationSyllabi::class)->execute($convocation->fresh()));
        $this->assertSame(2, Syllabus::query()->count());
    }

    public function test_opening_is_atomic_when_a_parallel_has_no_current_teacher(): void
    {
        $scheduledSubject = Parallel::query()->firstOrFail();
        Parallel::query()->create(['periodo_academico_id' => $scheduledSubject->periodo_academico_id, 'asignatura_id' => $scheduledSubject->asignatura_id, 'codigo' => 'B', 'jornada' => 'matutina']);
        $convocation = $this->createPreparedConvocation();

        $this->assertOpeningFails($convocation);

        $this->assertSame('preparacion', $convocation->fresh()->estado);
        $this->assertDatabaseCount('silabos', 0);
        $this->assertDatabaseMissing('eventos_auditoria', ['accion' => 'convocatoria.abierta']);
    }

    public function test_opening_reports_how_many_parallels_lack_a_teacher(): void
    {
        $scheduledSubject = Parallel::query()->firstOrFail();
        Parallel::query()->create(['periodo_academico_id' => $scheduledSubject->periodo_academico_id, 'asignatura_id' => $scheduledSubject->asignatura_id, 'codigo' => 'B', 'jornada' => 'matutina']);
        Parallel::query()->create(['periodo_academico_id' => $scheduledSubject->periodo_academico_id, 'asignatura_id' => $scheduledSubject->asignatura_id, 'codigo' => 'C', 'jornada' => 'matutina']);
        $convocation = $this->createPreparedConvocation();

        $this->assertOpeningFails($convocation, 'Faltan 2 paralelos sin docente.');
        $this->assertDatabaseCount('silabos', 0);
    }

    public function test_opening_is_rejected_when_a_subject_has_no_parallel(): void
    {
        $convocation = $this->createPreparedConvocation();
        $scheduledSubject = Parallel::query()->firstOrFail();
        $other = $scheduledSubject->subject->replicate();
        $other->codigo_asignatura = 'OTRA-01';
        $other->nombre_asignatura = 'Materia sin paralelo';
        $other->save();

        $this->assertOpeningFails($convocation, 'Falta 1 materia sin paralelo.');
        $this->assertSame('preparacion', $convocation->fresh()->estado);
    }

    public function test_opening_is_rejected_without_curriculum_subjects(): void
    {
        $convocation = $this->createPreparedConvocation();
        $readiness = app(CoordinatorConvocationReadiness::class);
        $state = $readiness->inspect($convocation->carrera_id);
        $state['curriculum_ready'] = false;

        $this->assertContains('Falta registrar la malla de la carrera con sus materias.', $readiness->missingAcademicData($state));
    }

    public function test_teacher_copies_content_between_own_parallels_of_the_same_subject(): void
    {
        [$first, $second] = $this->openConvocationWithTwoParallels();
        $field = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail();

        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $first))
            ->assertOk()->assertJsonPath('syllabus.copy_sources', []);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $first))->assertSuccessful();
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$first, $field]), [
            'version_bloqueo' => 1,
            'value' => $this->freeTextValue('Descripción compartida.'),
        ])->assertOk();

        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $second))
            ->assertOk()
            ->assertJsonPath('syllabus.copy_sources.0.id', $first->id)
            ->assertJsonPath('syllabus.copy_sources.0.parallel', $first->parallel->codigo);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.copy-from', [$second, $first]), [
            'version_bloqueo' => $second->version_bloqueo,
        ])->assertOk()->assertJsonPath('syllabus.state', 'borrador');

        $second->refresh();
        $this->assertSame('borrador', $second->estado);
        $this->assertEquals(
            $this->freeTextValue('Descripción compartida.'),
            $second->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor,
        );
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'silabo.contenido_copiado', 'recurso_id' => $second->id]);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.copy-from', [$second, $first]), [
            'version_bloqueo' => $second->version_bloqueo - 1,
        ])->assertConflict();
    }

    public function test_copy_is_rejected_into_a_sent_syllabus_or_from_one_not_started(): void
    {
        [$first, $second] = $this->openConvocationWithTwoParallels();

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.copy-from', [$second, $first]), [
            'version_bloqueo' => $second->version_bloqueo,
        ])->assertUnprocessable();

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $first))->assertSuccessful();
        $second->update(['estado' => 'en_revision']);

        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $second))
            ->assertOk()->assertJsonPath('syllabus.copy_sources', []);
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.copy-from', [$second, $first]), [
            'version_bloqueo' => $second->version_bloqueo,
        ])->assertForbidden();
    }

    public function test_teacher_starts_autosaves_and_stale_version_returns_recoverable_conflict(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $syllabus->refresh();
        $field = FieldDefinition::query()
            ->where('clave', 'metodologia')->firstOrFail();

        $response = $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => 1,
            'value' => $this->freeTextValue('Descripción académica verificable.'),
        ])->assertOk();

        $this->assertSame(2, $response->json('version_bloqueo'));
        $this->assertDatabaseHas('valores_campo', ['silabo_id' => $syllabus->id, 'definicion_campo_id' => $field->id]);

        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => 1,
            'value' => $this->freeTextValue('Escritura obsoleta.'),
        ])->assertConflict()
            ->assertJsonPath('code', 'conflicto_version_borrador')
            ->assertJsonPath('version_bloqueo_actual', 2);

        $this->assertEquals($this->freeTextValue('Descripción académica verificable.'), $syllabus->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor);
    }

    public function test_teacher_writes_free_text_with_lists_and_tables_and_empty_text_is_missing(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $field = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail();
        $this->assertSame('texto', $field->tipo);
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $url = route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]);
        $paragraph = fn (string $text, array $marks = []): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, 'marks' => $marks]]];

        $this->actingAsTeacher()->patchJson($url, ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'nombre_asignatura']]]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['value' => 'El texto libre contiene un elemento no permitido.']);

        $this->actingAsTeacher()->patchJson($url, ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]])->assertOk();
        $validation = $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $this->assertTrue(collect($validation->json('validation.results'))
            ->contains(fn (array $issue): bool => $issue['field_id'] === $field->id && $issue['code'] === 'campo_obligatorio_faltante'));

        $value = ['type' => 'doc', 'content' => [
            $paragraph('Enfoque práctico.', [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['color' => '#FF0000']]]),
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$paragraph('Semana 1: requisitos')]]]],
            ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
                ['type' => 'tableHeader', 'attrs' => ['colspan' => 1, 'rowspan' => 1, 'backgroundColor' => '#000000'], 'content' => [$paragraph('Unidad')]],
                ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [$paragraph('Pruebas')]],
            ]]]],
        ]];
        $this->actingAsTeacher()->patchJson($url, ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $value])->assertOk();
        $stored = $syllabus->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor;
        $this->assertSame([['type' => 'bold']], $stored['content'][0]['content'][0]['marks']);
        $this->assertSame(['colspan' => 1, 'rowspan' => 1], $stored['content'][2]['content'][0]['content'][0]['attrs']);

        $resolved = TemplateDocumentResolver::resolve(TemplateDocumentDefaults::freeTextSheet('descripcion', 'Descripción'), [
            ['key' => 'descripcion', 'type' => 'texto_libre', 'value' => $stored],
        ], [], null);
        $this->assertSame(['paragraph', 'bulletList', 'table'], array_column($resolved['content'], 'type'));
        $this->assertSame("Enfoque práctico.\n• Semana 1: requisitos\nUnidad | Pruebas", FreeText::plainText($stored));
    }

    public function test_teacher_api_receives_the_document_presentation_and_template_variables(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        config(['sanctum.stateful' => ['localhost:5173']]);

        $this->actingAsTeacher()
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()
            ->assertJsonPath('syllabus.presentation.title', 'PROGRAMA DE ASIGNATURA (SÍLABO)')
            ->assertJsonPath('syllabus.presentation.appearance.font_family', 'Arial')
            ->assertJsonMissingPath('syllabus.identification')
            ->assertJsonStructure(['syllabus' => ['template_variables']])
            ->assertJsonStructure([
                'syllabus' => [
                    'presentation' => ['logos' => ['institution', 'faculty']],
                    'sections' => [['blocks' => [['document', 'fields']]]],
                ],
            ]);
    }

    public function test_teacher_payload_reports_editability_and_pause_reason(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();

        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()
            ->assertJsonPath('syllabus.editable', true)
            ->assertJsonPath('syllabus.edit_block_reason', null);

        $syllabus->convocation()->update(['estado' => Convocation::STATE_PAUSED]);

        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()
            ->assertJsonPath('syllabus.editable', false)
            ->assertJsonPath('syllabus.edit_block_reason', 'La convocatoria está en pausa. Podrá seguir editando cuando se reanude.');
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertForbidden();
    }

    public function test_inherited_field_and_coordinator_edit_are_denied(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $syllabus->refresh();
        $inherited = FieldDefinition::query()
            ->where('heredado', true)->firstOrFail();

        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $inherited]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'value' => $this->freeTextValue('Intento de sobrescritura'),
        ])->assertUnprocessable()->assertJsonValidationErrors('field');

        $editable = FieldDefinition::query()
            ->where('editable_docente', true)->firstOrFail();
        $this->actingAsCoordinator()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $editable]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'value' => 'PV-16 no autorizado',
        ])->assertForbidden();
    }

    public function test_autosave_enforces_template_type_and_rejects_html_in_long_text(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $syllabus->refresh();
        // En el formato oficial el único texto largo (formación) se hereda del perfil: se vuelve editable para probar el tipo.
        $field = FieldDefinition::query()
            ->where('clave', 'formacion_experiencia')
            ->firstOrFail();
        $field->update(['heredado' => false, 'origen_maestro' => null, 'editable_docente' => true]);
        $syllabus->values()->where('definicion_campo_id', $field->id)->delete();

        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'value' => '<script>contenido no permitido</script>',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->assertDatabaseMissing('valores_campo', [
            'silabo_id' => $syllabus->id,
            'definicion_campo_id' => $field->id,
        ]);
        $this->assertSame($syllabus->version_bloqueo, $syllabus->fresh()->version_bloqueo);
    }

    public function test_deterministic_validation_tracks_required_fields_without_ai(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $syllabus->refresh();

        $first = $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $this->assertGreaterThan(0, $first->json('validation.blocking_errors'));
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'silabo.validado', 'recurso_id' => $syllabus->id]);

        $requiredEditable = FieldDefinition::query()
            ->where('heredado', false)->where('editable_docente', true)
            ->get();
        foreach ($requiredEditable as $field) {
            $payload = match (true) {
                in_array($field->tipo, ['texto_libre', 'texto', 'ficha'], true) => ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->freeTextValue("Contenido {$field->clave}")],
                $field->tipo === 'tabla' => ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->filledTableValue($field)],
                $field->tipo === 'seleccion_unica' => ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => (string) ($field->opciones[0] ?? '')],
                default => ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => "Contenido {$field->clave}"],
            };
            $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), $payload)->assertOk();
        }

        $last = $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $this->assertSame(0, $last->json('validation.blocking_errors'));
    }

    public function test_every_teacher_field_is_required_only_when_sending(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $text = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail();
        $empty = FieldDefinition::query()->where('clave', 'unidades')->firstOrFail();

        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $text]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => $this->freeTextValue(str_repeat('Objetivo amplio. ', 200)),
        ])->assertOk();

        $detail = $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk();
        $fields = collect($detail->json('syllabus.sections'))->flatMap(fn ($section) => $section['blocks'])->flatMap(fn ($block) => $block['fields'])->keyBy('key');
        $this->assertTrue($fields['unidades']['required']);
        $this->assertArrayNotHasKey('rules', $fields['metodologia']);

        $validation = $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $messages = collect($validation->json('validation.results'))->groupBy('field_id');
        $this->assertArrayNotHasKey($text->id, $messages->all());
        $this->assertSame('«'.$empty->etiqueta.'» está vacío. Complétalo antes de enviar.', $messages[$empty->id]->first()['message']);
    }

    public function test_validation_skips_non_editable_and_automatic_parts_and_sends_a_missing_profile_to_coordination(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $locked = FieldDefinition::query()->where('heredado', false)->where('clave', 'metodologia')->firstOrFail();
        $locked->update(['editable_docente' => false]);
        $inherited = FieldDefinition::query()->where('heredado', true)->where('origen_maestro', FieldDefinition::ORIGIN_PROFILE)->where('editable_docente', false)->firstOrFail();
        $automatic = FieldDefinition::query()->where('clave', 'asignatura')->firstOrFail();
        $syllabus->values()->where('definicion_campo_id', $inherited->id)->delete();

        $validation = $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $messages = collect($validation->json('validation.results'))->groupBy('field_id');

        $this->assertArrayNotHasKey($automatic->id, $messages->all());
        $this->assertArrayNotHasKey($locked->id, $messages->all());
        $this->assertSame('La asignatura no tiene ficha: pídala al coordinador.', $messages[$inherited->id]->first()['message']);
        $this->assertSame('error', $messages[$inherited->id]->first()['severity']);
    }

    public function test_role_navigation_endpoints_do_not_mix_coordinator_and_teacher_privileges(): void
    {
        $this->actingAsTeacher()->getJson(route('api.v1.coordination.convocations.index'))->assertForbidden();
        $this->actingAsCoordinator()->getJson(route('api.v1.teacher.syllabi.index'))->assertForbidden();
    }

    private function createPreparedConvocation(?string $periodId = null, bool $withFacultyLogo = true): Convocation
    {
        if ($withFacultyLogo) {
            $faculty = $this->coordinatorContext->career()->firstOrFail()->faculty()->firstOrFail();
            app(InstitutionalLogos::class)->storeFaculty($faculty, $this->transparentPng(600, 180));
        }

        $template = $this->publishedConfiguration();
        $process = $periodId === null
            ? $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())
            : SyllabusProcess::query()->create([
                'periodo_academico_id' => $periodId,
                'inicia_en' => now()->subDay(),
                'entrega_en' => now()->addMonth(),
                'estado' => SyllabusProcess::STATE_OPEN,
            ]);

        $convocation = Convocation::query()->create([
            'carrera_id' => $this->coordinatorContext->carrera_id,
            'proceso_id' => $process->id,
            'estado' => 'preparacion',
        ]);

        return $convocation;
    }

    private function publishedConfiguration(): SyllabusTemplate
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla I-03']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->fillSubjectProfiles();

        return $template->fresh();
    }

    private function openConvocationAndGetSyllabus(): Syllabus
    {
        $convocation = $this->createPreparedConvocation();
        $this->openPrepared($convocation);

        return Syllabus::query()->firstOrFail();
    }

    /** @return array{Syllabus, Syllabus} */
    private function openConvocationWithTwoParallels(): array
    {
        $scheduledSubject = Parallel::query()->firstOrFail();
        $parallel = Parallel::query()->create([
            'periodo_academico_id' => $scheduledSubject->periodo_academico_id, 'asignatura_id' => $scheduledSubject->asignatura_id,
            'codigo' => 'Z',
            'jornada' => 'matutina',
        ]);
        TeacherAssignment::query()->create([
            'usuario_id' => $this->teacher->id,
            'paralelo_id' => $parallel->id,
            'activo' => true,
        ]);
        $this->openPrepared($this->createPreparedConvocation());

        return [
            Syllabus::query()->where('paralelo_id', '!=', $parallel->id)->firstOrFail(),
            Syllabus::query()->where('paralelo_id', $parallel->id)->firstOrFail(),
        ];
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

        return $this;
    }

    private function openPrepared(Convocation $convocation): void
    {
        $request = Request::create('/');
        $request->attributes->set('active_role', $this->coordinatorContext);
        app(OpenConvocation::class)->execute($convocation->id, $this->coordinator, $request);
    }

    private function assertOpeningFails(Convocation $convocation, ?string $message = null): void
    {
        try {
            $this->openPrepared($convocation);
            $this->fail('La apertura debía rechazarse.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertArrayHasKey('convocation', $errors);
            if ($message !== null) {
                $this->assertSame($message, $errors['convocation'][0]);
            }
        }
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->coordinatorContext->id]);

        return $this;
    }

    private function actingAsTeacher(): static
    {
        $this->actingAs($this->teacher)->withSession(['active_role_assignment_id' => $this->teacherContext->id]);

        return $this;
    }
}
