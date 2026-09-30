<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectLearningOutcomeBase;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\Actions\OpenConvocation;
use App\Modules\Syllabus\Application\CoordinatorConvocationReadiness;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Application\RequiredAcademicTemplateData;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\ValidationRun;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\MakesTransparentPng;
use Tests\Support\UsesProjectData;
use Tests\TestCase;

class ConvocationAndDraftTest extends TestCase
{
    use CreatesSyllabusProcess;
    use MakesTransparentPng;
    use RefreshDatabase;
    use UsesProjectData;

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
        $master = $syllabus->values()->where('heredado', true)->firstOrFail();
        $this->assertSame('Arquitectura de Software', $master->valor['nombre']);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'convocatoria.abierta',
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

    public function test_confirmed_curricular_outcomes_are_frozen_with_the_syllabus_and_resolved_in_order(): void
    {
        $scheduled = ScheduledSubject::query()->firstOrFail();
        $base = SubjectLearningOutcomeBase::query()->create([
            'asignatura_id' => $scheduled->asignatura_id,
            'nombre_fuente' => 'Proyecto de carrera de prueba',
            'localizador_fuente' => 'Página 12, ficha de la asignatura',
            'confirmado_por' => $this->coordinator->id,
            'confirmado_en' => now(),
        ]);
        $base->outcomes()->create(['texto_resultado' => 'Compara alternativas de diseño.', 'posicion_resultado' => 1]);
        $base->outcomes()->create(['texto_resultado' => 'Justifica una arquitectura.', 'posicion_resultado' => 2]);

        $syllabus = $this->openConvocationAndGetSyllabus();
        $snapshot = app(SyllabusSnapshot::class)->build($syllabus);
        $this->assertSame("1. Compara alternativas de diseño.\n2. Justifica una arquitectura.", $snapshot['template_variables']['resultados_aprendizaje_asignatura']);
        $this->assertSame('Proyecto de carrera de prueba', $snapshot['template_variables']['fuente_resultados_aprendizaje']);
        $this->assertSame('Página 12, ficha de la asignatura', $snapshot['template_variables']['ubicacion_resultados_aprendizaje']);
        $this->assertSame($this->coordinator->id, data_get($snapshot, 'academic_context.subject.learning_outcomes.confirmed_by.id'));

        $base = SubjectLearningOutcomeBase::query()->firstOrFail();
        $base->outcomes()->firstOrFail()->update(['texto_resultado' => 'Texto curricular posterior.']);
        $base->update(['nombre_fuente' => 'Otra fuente posterior']);
        $this->assertSame($snapshot['template_variables'], app(SyllabusSnapshot::class)->build($syllabus->fresh())['template_variables']);
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()
            ->assertJsonPath('syllabus.template_variables.resultados_aprendizaje_asignatura', $snapshot['template_variables']['resultados_aprendizaje_asignatura']);
    }

    public function test_a_missing_curricular_value_blocks_validation_when_used_in_a_fixed_block(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->assertSame([], app(RequiredAcademicTemplateData::class)->issues($syllabus->contexto_academico));
        $block = FieldDefinition::query()->where('clave', 'resultados_aprendizaje')->firstOrFail()->block;
        $block->update(['configuracion' => [
            'content_type' => 'fixed',
            'document' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'resultados_aprendizaje_asignatura']]]],
                ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'resultados_aprendizaje_asignatura']]]],
            ]],
        ]]);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertOk();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertOk();
        $run = ValidationRun::query()->latest('completado_en')->firstOrFail();
        $issues = $run->results()->where('codigo', 'dato_curricular_faltante')->get();
        $this->assertCount(1, $issues, 'Repetir la variable no debe duplicar el error.');
        $this->assertStringContainsString('Coordinación', $issues->first()->mensaje);
        $this->assertNull($issues->first()->definicion_campo_id);

        $context = $syllabus->contexto_academico;
        $context['subject']['learning_outcomes'] = ['outcomes' => [['text' => 'Resultado confirmado.']]];
        $this->assertSame([], app(RequiredAcademicTemplateData::class)->issues($context));
    }

    public function test_curricular_variables_accept_old_snapshots_and_have_useful_template_samples(): void
    {
        $variables = TemplateVariables::resolve(['identification' => ['subject' => 'Materia histórica']]);
        $this->assertSame('Materia histórica', $variables['nombre_asignatura']);
        $this->assertSame('', $variables['resultados_aprendizaje_asignatura']);
        $samples = collect(TemplateVariables::catalog())->keyBy('key');
        $this->assertStringContainsString('1. ', $samples['resultados_aprendizaje_asignatura']['sample']);
        $this->assertNotEmpty($samples['fuente_resultados_aprendizaje']['sample']);
    }

    public function test_explicit_per_parallel_mode_generates_one_syllabus_for_each_parallel(): void
    {
        $scheduledSubject = ScheduledSubject::query()->firstOrFail();
        $parallel = Parallel::query()->create([
            'programacion_asignatura_id' => $scheduledSubject->id,
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
        $parallel = Parallel::query()->create([
            'programacion_asignatura_id' => ScheduledSubject::query()->firstOrFail()->id,
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
        $scheduledSubject = ScheduledSubject::query()->firstOrFail();
        Parallel::query()->create(['programacion_asignatura_id' => $scheduledSubject->id, 'codigo' => 'B', 'jornada' => 'matutina']);
        $convocation = $this->createPreparedConvocation();

        $this->assertOpeningFails($convocation);

        $this->assertSame('preparacion', $convocation->fresh()->estado);
        $this->assertDatabaseCount('silabos', 0);
        $this->assertDatabaseMissing('eventos_auditoria', ['accion_evento_auditoria' => 'convocatoria.abierta']);
    }

    public function test_opening_reports_how_many_parallels_lack_a_teacher(): void
    {
        $scheduledSubject = ScheduledSubject::query()->firstOrFail();
        Parallel::query()->create(['programacion_asignatura_id' => $scheduledSubject->id, 'codigo' => 'B', 'jornada' => 'matutina']);
        Parallel::query()->create(['programacion_asignatura_id' => $scheduledSubject->id, 'codigo' => 'C', 'jornada' => 'matutina']);
        $convocation = $this->createPreparedConvocation();

        $this->assertOpeningFails($convocation, 'Faltan 2 paralelos sin docente.');
        $this->assertDatabaseCount('silabos', 0);
    }

    public function test_opening_is_rejected_when_a_scheduled_subject_has_no_parallel(): void
    {
        $convocation = $this->createPreparedConvocation();
        $scheduledSubject = ScheduledSubject::query()->firstOrFail();
        $other = $scheduledSubject->subject->replicate();
        $other->codigo_asignatura = 'OTRA-01';
        $other->nombre_asignatura = 'Materia sin paralelo';
        $other->save();
        ScheduledSubject::query()->create([
            'asignatura_id' => $other->id,
            'periodo_academico_id' => $scheduledSubject->periodo_academico_id,
            'activo' => true,
        ]);

        $this->assertOpeningFails($convocation, 'Falta 1 materia programada sin paralelo.');
        $this->assertSame('preparacion', $convocation->fresh()->estado);
    }

    public function test_opening_requires_the_project_data_used_by_the_template(): void
    {
        $convocation = $this->createPreparedConvocation();
        $subject = ScheduledSubject::query()->firstOrFail()->subject;
        $outcomes = $this->useProjectData('resultados_de_aprendizaje', 'Resultados de aprendizaje');
        $profile = $this->useProjectData('perfil_de_egreso', 'Perfil de egreso', 'texto');
        CurricularConcept::query()->create(['clave_concepto' => 'convenios', 'etiqueta_concepto' => 'Convenios',
            'tipo_concepto' => 'lista', 'columnas_concepto' => null]);

        $this->assertOpeningFails($convocation, "Falta confirmar «Perfil de egreso» en Proyecto de carrera para {$subject->nombre}.");
        $readiness = app(CoordinatorConvocationReadiness::class);
        $this->assertSame([
            "Falta confirmar «Perfil de egreso» en Proyecto de carrera para {$subject->nombre}.",
            "Falta confirmar «Resultados de aprendizaje» en Proyecto de carrera para {$subject->nombre}.",
        ], $readiness->inspect($convocation->carrera_id)['project_data_missing']);

        $confirmed = fn ($concept, string $subjectId, mixed $value): CurricularValue => CurricularValue::query()->create([
            'concepto_id' => $concept->id, 'carrera_id' => $convocation->carrera_id, 'asignatura_id' => $subjectId,
            'valor_curricular' => $value, 'evidencia_curricular' => [['source_id' => 'prueba', 'locator' => 'Página 3', 'quote' => 'Extracto sintético']], 'confirmado_por' => $this->coordinator->id, 'confirmado_en' => now(),
        ]);
        $confirmed($profile, $subject->id, 'Profesional de software.');
        $confirmed($outcomes, $subject->id, []);
        $this->assertOpeningFails($convocation, "Falta confirmar «Resultados de aprendizaje» en Proyecto de carrera para {$subject->nombre}.");

        CurricularValue::query()->where('concepto_id', $outcomes->id)->update(['valor_curricular' => json_encode(['Analiza requisitos.'])]);
        $this->assertSame([], $readiness->inspect($convocation->carrera_id)['project_data_missing']);
        $this->actingAsAdministrator()->getJson(route('api.v1.admin.academic.index'))
            ->assertOk()->assertJsonPath('lock_reason', null)->assertJsonPath('catalogs.periods.0.lock_reason', null);
        $this->openPrepared($convocation);
        $this->assertSame('abierta', $convocation->fresh()->estado);

        $this->actingAsAdministrator()->getJson(route('api.v1.admin.academic.index'))
            ->assertOk()->assertJsonPath('lock_reason', 'Convocatoria activa')
            ->assertJsonPath('catalogs.periods.0.lock_reason', 'Convocatoria activa');
    }

    public function test_opening_is_rejected_without_curriculum_subjects(): void
    {
        $convocation = $this->createPreparedConvocation();
        $readiness = app(CoordinatorConvocationReadiness::class);
        $state = $readiness->inspect($convocation->carrera_id);
        $state['curriculum_ready'] = false;

        $this->assertContains('Falta registrar la malla de la carrera con sus materias.', $readiness->missingAcademicData($state));
    }

    public function test_opening_and_resuming_do_not_require_active_sources(): void
    {
        $convocation = $this->createPreparedConvocation();
        AcademicSource::query()->delete();
        $this->openPrepared($convocation);
        $this->actingAsCoordinator();
        $this->assertSame('abierta', $convocation->fresh()->estado);
        $this->assertDatabaseCount('silabos', 1);
        $this->assertDatabaseCount('fuentes_academicas', 0);
        $this->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))->assertOk();
        $this->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))->assertOk();
        $this->assertSame('abierta', $convocation->fresh()->estado);
    }

    public function test_teacher_starts_autosaves_and_stale_version_returns_recoverable_conflict(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $syllabus->refresh();
        $field = FieldDefinition::query()
            ->where('clave', 'descripcion')->firstOrFail();

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
        $field = FieldDefinition::query()->where('clave', 'descripcion')->firstOrFail();
        $this->assertSame('texto_libre', $field->tipo);
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $url = route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]);
        $paragraph = fn (string $text, array $marks = []): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, 'marks' => $marks]]];

        $this->actingAsTeacher()->patchJson($url, ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'nombre_asignatura']]]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['value' => 'El texto libre contiene un elemento no permitido.']);

        $this->actingAsTeacher()->patchJson($url, ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]])->assertOk();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $this->assertTrue(ValidationRun::query()->latest('completado_en')->firstOrFail()->results()
            ->where('definicion_campo_id', $field->id)->where('codigo', 'campo_obligatorio_faltante')->exists());

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
            'value' => 'Intento de sobrescritura',
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
        $field = FieldDefinition::query()
            ->where('tipo', 'texto_largo')
            ->firstOrFail();

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

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $firstRun = ValidationRun::query()->latest('completado_en')->firstOrFail();
        $this->assertSame('free-text-v6', $firstRun->version_reglas);
        $this->assertGreaterThan(0, $firstRun->errores_bloqueantes);

        $requiredEditable = FieldDefinition::query()
            ->where('heredado', false)
            ->get();
        foreach ($requiredEditable as $field) {
            $payload = match (true) {
                $field->tipo === 'texto_libre' => ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->freeTextValue("Contenido {$field->clave}")],
                $field->tipo === 'seleccion_unica' => ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => (string) ($field->opciones[0] ?? '')],
                default => ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => "Contenido {$field->clave}"],
            };
            $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), $payload)->assertOk();
        }

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $lastRun = ValidationRun::query()->whereKeyNot($firstRun->id)->firstOrFail();
        $this->assertSame(0, $lastRun->errores_bloqueantes);
    }

    public function test_every_teacher_field_is_required_only_when_sending(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $text = FieldDefinition::query()->where('clave', 'objetivo_general')->firstOrFail();
        $empty = FieldDefinition::query()->where('clave', 'habilidades_blandas')->firstOrFail();

        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $text]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => $this->freeTextValue(str_repeat('Objetivo amplio. ', 200)),
        ])->assertOk();

        $detail = $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk();
        $fields = collect($detail->json('syllabus.sections'))->flatMap(fn ($section) => $section['blocks'])->flatMap(fn ($block) => $block['fields'])->keyBy('key');
        $this->assertTrue($fields['habilidades_blandas']['required']);
        $this->assertArrayNotHasKey('rules', $fields['objetivo_general']);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $run = ValidationRun::query()->latest('completado_en')->firstOrFail();
        $messages = $run->results()->get()->groupBy('definicion_campo_id');
        $this->assertArrayNotHasKey($text->id, $messages->all());
        $this->assertSame('«'.$empty->etiqueta.'» está vacío. Complétalo antes de enviar.', $messages[$empty->id]->first()->mensaje);
    }

    public function test_part_shown_only_when_another_matches_is_not_required_while_hidden_and_prints_empty(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $has = FieldDefinition::query()->where('clave', 'discapacidad_tiene')->firstOrFail();
        $type = FieldDefinition::query()->where('clave', 'discapacidad_tipo')->firstOrFail();
        $adaptation = FieldDefinition::query()->where('clave', 'discapacidad_adaptacion')->firstOrFail();
        $save = fn (FieldDefinition $field, string $value) => $this->actingAsTeacher()->patchJson(
            route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]),
            ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $value],
        )->assertOk();
        $missing = function () use ($syllabus): array {
            return array_column($this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))
                ->assertSuccessful()->json('validation.results'), 'field_id');
        };

        $detail = $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk();
        $fields = collect($detail->json('syllabus.sections'))->flatMap(fn ($section) => $section['blocks'])->flatMap(fn ($block) => $block['fields'])->keyBy('key');
        $this->assertSame(['key' => 'discapacidad_tiene', 'value' => 'Sí'], $fields['discapacidad_tipo']['show_when']);
        $this->assertNull($fields['discapacidad_tiene']['show_when']);

        $save($has, 'Sí');
        $this->assertContains($type->id, $missing());
        $this->assertContains($adaptation->id, $missing());

        $save($type, 'Visual');
        $save($adaptation, 'Material ampliado');
        $save($has, 'No');
        $this->assertNotContains($type->id, $missing());
        $this->assertNotContains($adaptation->id, $missing());
        $this->assertSame('Visual', $syllabus->values()->where('definicion_campo_id', $type->id)->value('valor'));

        $snapshot = app(SyllabusSnapshot::class)->build($syllabus->fresh());
        $this->assertSame('', $snapshot['identification']['disability_type']);
        $block = collect($snapshot['sections'])->flatMap(fn ($section) => $section['blocks'])
            ->first(fn ($block) => collect($block['fields'])->contains('key', 'discapacidad_tipo'));
        $snapshotFields = collect($block['fields'])->keyBy('key');
        $this->assertTrue($snapshotFields['discapacidad_tipo']['hidden']);
        $this->assertSame('Visual', $snapshotFields['discapacidad_tipo']['value']);
        $resolved = json_encode(TemplateDocumentResolver::resolve($block['document'], $block['fields'], [], null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Visual', $resolved);
        $this->assertStringNotContainsString('Material ampliado', $resolved);

        $save($has, 'Sí');
        $this->assertSame('Visual', app(SyllabusSnapshot::class)->build($syllabus->fresh())['identification']['disability_type']);
    }

    public function test_validation_skips_fixed_and_non_editable_parts_and_sends_missing_institutional_data_to_coordination(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $template = SyllabusTemplate::query()->firstOrFail();
        $fixedBlock = $template->blocks()->first(fn ($block): bool => $block->contentType() === 'fixed');
        $this->assertNotNull($fixedBlock);
        $inFixed = FieldDefinition::query()->create([
            'plantilla_id' => $template->id, 'campo_plantilla_id' => $fixedBlock->id, 'clave' => 'resto_fijo',
            'etiqueta' => 'Resto de texto fijo', 'tipo' => 'texto_largo', 'heredado' => false,
            'editable_docente' => true, 'ia_habilitada' => false, 'posicion' => 99,
        ]);
        $locked = FieldDefinition::query()->where('heredado', false)->where('clave', 'habilidades_blandas')->firstOrFail();
        $locked->update(['editable_docente' => false]);
        $inherited = FieldDefinition::query()->where('heredado', true)->firstOrFail();
        $syllabus->values()->where('definicion_campo_id', $inherited->id)->delete();

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $messages = ValidationRun::query()->latest('completado_en')->firstOrFail()
            ->results()->get()->groupBy('definicion_campo_id');

        $this->assertArrayNotHasKey($inFixed->id, $messages->all());
        $this->assertArrayNotHasKey($locked->id, $messages->all());
        $this->assertSame(
            "Avise a Coordinación: falta el dato «{$inherited->etiqueta}» en la información de la carrera.",
            $messages[$inherited->id]->first()->mensaje,
        );
        $this->assertSame('error', $messages[$inherited->id]->first()->severidad);
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

        [$template, $source] = $this->publishedConfiguration();
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

    /** @return array{SyllabusTemplate, AcademicSource} */
    private function publishedConfiguration(): array
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla I-03']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();

        $source = AcademicSource::query()->create([
            'carrera_id' => $this->coordinatorContext->carrera_id, 'nombre' => 'Fuente I-03',
            'activo' => true, 'contenido' => 'Evidencia histórica conservada.',
        ]);

        return [$template->fresh(), $source->fresh()];
    }

    private function openConvocationAndGetSyllabus(): Syllabus
    {
        $convocation = $this->createPreparedConvocation();
        $this->openPrepared($convocation);

        return Syllabus::query()->firstOrFail();
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
