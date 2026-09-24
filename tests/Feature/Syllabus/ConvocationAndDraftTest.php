<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\Actions\OpenConvocation;
use App\Modules\Syllabus\Application\CoordinatorConvocationReadiness;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\ValidationRun;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']), ['reason' => 'Se agrega el paralelo B a la malla.'])
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

        // Idempotente: una segunda pasada no duplica expedientes.
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
            'campus_programacion' => $scheduledSubject->campus_programacion,
            'modalidad' => $scheduledSubject->modalidad,
            'activo' => true,
        ]);

        $this->assertOpeningFails($convocation, 'Falta 1 materia programada sin paralelo.');
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

    public function test_opening_is_rejected_without_active_sources(): void
    {
        $convocation = $this->createPreparedConvocation();
        AcademicSource::query()->update(['activo' => false]);

        $this->assertOpeningFails($convocation, 'Falta registrar al menos una fuente académica activa.');
        $this->assertDatabaseCount('silabos', 0);
    }

    public function test_archived_source_blocks_convocation_opening(): void
    {
        $convocation = $this->createPreparedConvocation();
        AcademicSource::query()
            ->whereIn('id', $convocation->sources()->pluck('fuentes_academicas.id'))
            ->update(['activo' => false]);

        $this->assertOpeningFails($convocation);

        $this->assertDatabaseCount('silabos', 0);
        $this->assertSame('preparacion', $convocation->fresh()->estado);
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
            'value' => 'Descripción académica verificable.',
        ])->assertOk();

        $this->assertSame(2, $response->json('version_bloqueo'));
        $this->assertDatabaseHas('valores_campo', ['silabo_id' => $syllabus->id, 'definicion_campo_id' => $field->id]);

        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => 1,
            'value' => 'Escritura obsoleta.',
        ])->assertConflict()
            ->assertJsonPath('code', 'conflicto_version_borrador')
            ->assertJsonPath('version_bloqueo_actual', 2);

        $this->assertSame('Descripción académica verificable.', $syllabus->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor);
    }

    public function test_teacher_api_receives_the_document_presentation_and_identification_grid(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        config(['sanctum.stateful' => ['localhost:5173']]);

        $this->actingAsTeacher()
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()
            ->assertJsonPath('syllabus.presentation.title', 'PROGRAMA DE ASIGNATURA (SÍLABO)')
            ->assertJsonPath('syllabus.presentation.appearance.font_family', 'Arial')
            ->assertJsonPath('syllabus.identification.0.0.style', 'blue')
            ->assertJsonPath('syllabus.identification.0.0.span', 9)
            ->assertJsonStructure([
                'syllabus' => [
                    'presentation' => ['logos' => ['institution', 'faculty']],
                    'sections' => [['blocks' => [['table', 'fields']]]],
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
        $this->assertSame('planning-v3', $firstRun->version_reglas);
        $this->assertGreaterThan(0, $firstRun->errores_bloqueantes);

        $requiredEditable = FieldDefinition::query()
            ->where('heredado', false)
            ->get();
        foreach ($requiredEditable as $field) {
            $payload = match (true) {
                $field->clave === 'unidades' => [
                    'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
                    'rows' => $this->validPlanningRows(),
                ],
                $field->tipo === 'repetible' => ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'rows' => [['data' => ['texto' => "Contenido {$field->clave}"]]]],
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

        // El borrador acepta texto largo y campos vacíos: no hay límites configurables.
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $text]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => str_repeat('Objetivo amplio. ', 200),
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

    public function test_validation_skips_fixed_and_non_editable_parts_and_sends_missing_institutional_data_to_coordination(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $template = SyllabusTemplate::query()->firstOrFail();
        $fixedBlock = $template->blocks()->first(fn ($block): bool => $block->contentType() === 'fixed');
        $this->assertNotNull($fixedBlock);
        // Una definición antigua dentro de un texto fijo no se le pide al docente.
        $inFixed = FieldDefinition::query()->create([
            'plantilla_id' => $template->id, 'bloque_plantilla_id' => $fixedBlock->id, 'clave' => 'resto_fijo',
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

    public function test_planning_with_wrong_hours_can_be_saved_but_fails_deterministic_validation(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $field = FieldDefinition::query()
            ->where('clave', 'unidades')
            ->firstOrFail();
        $rows = $this->validPlanningRows();
        $rows[1]['data']['acd'] = 2;

        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'rows' => $rows,
        ])->assertOk();

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.validate', $syllabus))->assertSuccessful();
        $run = ValidationRun::query()->latest('completado_en')->firstOrFail();

        $this->assertGreaterThan(0, $run->errores_bloqueantes);
        $this->assertDatabaseHas('resultados_validacion', [
            'ejecucion_validacion_id' => $run->id,
            'definicion_campo_id' => $field->id,
            'codigo_resultado_validacion' => 'horas_planificacion_no_coinciden',
            'severidad_resultado_validacion' => 'error',
        ]);
    }

    public function test_repeatable_rows_keep_identity_when_an_earlier_row_is_removed(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $syllabus->refresh();
        $field = FieldDefinition::query()
            ->where('tipo', 'repetible')
            ->firstOrFail();

        $created = $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'rows' => [
                ['data' => ['texto' => 'Primera fila']],
                ['data' => ['texto' => 'Segunda fila']],
            ],
        ])->assertOk();
        $secondRowId = $created->json('rows.1.id');

        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $created->json('version_bloqueo'),
            'rows' => [
                ['id' => $secondRowId, 'data' => ['texto' => 'Segunda fila ajustada']],
            ],
        ])->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.id', $secondRowId)
            ->assertJsonPath('rows.0.posicion', 1);

        $this->assertDatabaseMissing('filas_repetibles', ['datos_fila_repetible->texto' => 'Primera fila']);
        $this->assertDatabaseHas('filas_repetibles', [
            'id' => $secondRowId,
            'posicion_fila_repetible' => 1,
            'datos_fila_repetible->texto' => 'Segunda fila ajustada',
        ]);
    }

    public function test_teacher_fills_table_cells_by_column_and_unit(): void
    {
        $syllabus = $this->openConvocationAndGetSyllabus();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus));
        $syllabus->refresh();
        $field = FieldDefinition::query()
            ->where('tipo', 'repetible')
            ->firstOrFail();

        // I-34: una celda por columna, unidad y fila de cabecera de la unidad.
        $saved = $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'rows' => [
                ['data' => ['_unit' => 1, '_kind' => 'unit', 'nombre' => 'Fundamentos']],
                ['data' => ['_unit' => 1, 'contenidos' => 'Socialización', 'acd' => 2, 'ape' => 1.5, 'aa' => '']],
            ],
        ])->assertOk();

        $this->assertSame('unit', $saved->json('rows.0.datos._kind'));
        $this->assertSame(2, $saved->json('rows.1.datos.acd'));
        $this->assertSame(1.5, $saved->json('rows.1.datos.ape'));

        // Una celda no puede ser una estructura anidada.
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $saved->json('version_bloqueo'),
            'rows' => [['data' => ['contenidos' => ['no' => 'permitido']]]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.data.contenidos']);
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

        // Esta ayuda prepara el estado previo para probar OpenConvocation en aislamiento.
        // El alta real de una carrera siempre pasa por CreateConvocation y abre de forma atómica.
        $convocation = Convocation::query()->create([
            'carrera_id' => $this->coordinatorContext->carrera_id,
            'proceso_id' => $process->id,
            'estado' => 'preparacion',
        ]);
        DB::table('fuentes_convocatoria')->insert([
            'id' => (string) Str::uuid(),
            'convocatoria_id' => $convocation->id,
            'fuente_academica_id' => $source->id,
        ]);

        return $convocation;
    }

    /** @return array{SyllabusTemplate, AcademicSource} */
    private function publishedConfiguration(): array
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla I-03']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.sources.store'), [
            'nombre' => 'Fuente I-03',
            'description' => 'Documento de apoyo del periodo.',
        ]);
        $source = AcademicSource::query()->firstOrFail();
        $this->actingAsCoordinator()->putJson(route('api.v1.coordination.sources.content.update', $source), [
            'content' => "## Perfil base\n\nEvidencia académica autorizada.",
        ]);

        return [$template->fresh(), $source->fresh()];
    }

    private function openConvocationAndGetSyllabus(): Syllabus
    {
        $convocation = $this->createPreparedConvocation();
        $this->openPrepared($convocation);

        return Syllabus::query()->firstOrFail();
    }

    /** @return list<array{data: array<string, int|string>}> */
    private function validPlanningRows(): array
    {
        $rows = [['data' => [
            '_unit' => 1,
            '_kind' => 'unit',
            'nombre' => 'Unidad integrada',
            'resultados' => 'Resultado de aprendizaje verificable',
        ]]];
        for ($week = 1; $week <= 16; $week++) {
            $rows[] = ['data' => [
                '_unit' => 1,
                'semana' => $week,
                'acd' => 4,
                'ape' => 2,
                'aa' => 6,
                'contenidos' => "Contenido de la semana {$week}",
            ]];
        }

        return $rows;
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

        return $this;
    }

    /** El alta real abre dentro de CreateConvocation; aquí se ejercita OpenConvocation aislada. */
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
