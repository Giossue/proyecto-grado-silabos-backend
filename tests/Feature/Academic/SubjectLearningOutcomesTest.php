<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectLearningOutcomeBase;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubjectLearningOutcomesTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private RoleAssignment $role;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->role = $this->coordinator->roleAssignments()->firstOrFail();
        $this->subject = Subject::query()->firstOrFail();
        $this->actingAsCoordinator();
    }

    public function test_reads_an_unconfigured_subject_without_inventing_outcomes(): void
    {
        $this->getJson($this->url())->assertOk()->assertJson([
            'subject_id' => $this->subject->id,
            'outcomes' => [], 'source_name' => '', 'source_locator' => '',
            'fingerprint' => null, 'confirmed_at' => null, 'confirmed_by' => null,
            'editable' => true, 'lock_reason' => null,
        ]);
        $this->assertDatabaseCount('bases_resultados_asignatura', 0);
    }

    public function test_confirmation_stores_ordered_outcomes_and_source_and_unchanged_save_is_a_noop(): void
    {
        $saved = $this->putJson($this->url(), $this->payload())->assertOk()
            ->assertJsonPath('confirmed_by.id', $this->coordinator->id)
            ->assertJsonPath('source_locator', 'Página 141, columna Resultados de aprendizaje')
            ->assertJsonPath('outcomes.0.position', 1)
            ->assertJsonPath('outcomes.1.position', 2)->json();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $saved['fingerprint']);
        $this->assertNotNull($saved['confirmed_at']);
        $this->assertDatabaseCount('bases_resultados_asignatura', 1);
        $this->assertDatabaseCount('resultados_aprendizaje_asignatura', 2);
        $this->getJson($this->url())->assertOk()->assertJsonPath('fingerprint', $saved['fingerprint']);

        $this->putJson($this->url(), $this->editPayload($saved))->assertOk()
            ->assertJsonPath('fingerprint', $saved['fingerprint']);
        $this->assertSame(1, AuditEvent::query()->where('accion', 'academico.resultados_aprendizaje.confirmados')->count());
    }

    public function test_editing_reorders_preserves_ids_and_removes_only_omitted_current_rows(): void
    {
        $saved = $this->putJson($this->url(), $this->payload())->assertOk()->json();
        $edited = $this->editPayload($saved);
        $edited['outcomes'] = [
            ['id' => $saved['outcomes'][1]['id'], 'text' => 'Aplica técnicas de control de calidad.'],
            ['text' => 'Interpreta los resultados de la evaluación.'],
        ];
        $this->putJson($this->url(), $edited)->assertOk()
            ->assertJsonPath('outcomes.0.id', $saved['outcomes'][1]['id'])
            ->assertJsonPath('outcomes.0.position', 1)
            ->assertJsonPath('outcomes.1.position', 2);
        $this->assertDatabaseMissing('resultados_aprendizaje_asignatura', ['id' => $saved['outcomes'][0]['id']]);
        $this->assertDatabaseCount('resultados_aprendizaje_asignatura', 2);
    }

    public function test_confirmation_provenance_content_and_concurrency_reference_are_required(): void
    {
        $this->putJson($this->url(), [
            'source_name' => ' ', 'source_locator' => '', 'outcomes' => [['text' => ' ']], 'confirmed' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'source_name', 'source_locator', 'outcomes.0.text', 'confirmed', 'expected_fingerprint',
        ]);
        $this->putJson($this->url(), [...$this->payload(), 'outcomes' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('outcomes');
        $this->assertDatabaseCount('bases_resultados_asignatura', 0);
    }

    public function test_teacher_and_administrator_cannot_manage_the_coordinators_outcomes(): void
    {
        foreach (['docente@silabos.test', 'admin@silabos.test'] as $email) {
            $user = User::query()->where('correo_electronico', $email)->firstOrFail();
            $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
            $this->getJson($this->url())->assertForbidden();
            $this->putJson($this->url(), $this->payload())->assertForbidden();
        }
    }

    public function test_coordinator_cannot_read_or_write_a_subject_from_another_career(): void
    {
        $otherCareer = $this->subject->career->replicate();
        $otherCareer->codigo_carrera = 'OTRA';
        $otherCareer->nombre = 'Otra carrera';
        $otherCareer->save();
        $otherSubject = $this->subject->replicate();
        $otherSubject->carrera_id = $otherCareer->id;
        $otherSubject->save();
        $this->getJson($this->url($otherSubject))->assertNotFound();
        $this->putJson($this->url($otherSubject), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('bases_resultados_asignatura', 0);
    }

    public function test_an_outcome_id_cannot_be_injected_or_repeated(): void
    {
        $saved = $this->putJson($this->url(), $this->payload())->assertOk()->json();
        $this->putJson($this->url(), [
            ...$this->editPayload($saved), 'outcomes' => [['id' => (string) Str::uuid(), 'text' => 'Texto ajeno.']],
        ])->assertUnprocessable()->assertJsonValidationErrors('outcomes.0.id');
        $duplicate = ['id' => $saved['outcomes'][0]['id'], 'text' => 'Resultado repetido.'];
        $this->putJson($this->url(), [...$this->editPayload($saved), 'outcomes' => [$duplicate, $duplicate]])
            ->assertUnprocessable()->assertJsonValidationErrors('outcomes.0.id');
        $this->getJson($this->url())->assertJsonPath('fingerprint', $saved['fingerprint']);
    }

    public function test_stale_creation_and_edit_are_rejected_without_overwriting_a_newer_confirmation(): void
    {
        $first = $this->putJson($this->url(), $this->payload())->assertOk()->json();
        $this->putJson($this->url(), $this->payload())->assertConflict()
            ->assertJsonPath('code', 'conflicto_resultados_aprendizaje');
        $second = $this->putJson($this->url(), [
            ...$this->editPayload($first), 'source_locator' => 'Página 142, corrección confirmada',
        ])->assertOk()->json();
        $this->putJson($this->url(), $this->editPayload($first))->assertConflict()
            ->assertJsonPath('code', 'conflicto_resultados_aprendizaje');
        $this->getJson($this->url())->assertJsonPath('fingerprint', $second['fingerprint']);
    }

    public function test_open_career_convocation_blocks_changes_even_with_institutional_pause(): void
    {
        $convocation = $this->convocation();
        $this->getJson($this->url())->assertOk()->assertJsonPath('editable', false)
            ->assertJsonPath('lock_reason', 'Convocatoria activa');
        $this->putJson($this->url(), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('process');
        $convocation->process()->firstOrFail()->update(['estado' => SyllabusProcess::STATE_PAUSED]);
        $this->putJson($this->url(), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('process');
        $this->assertDatabaseCount('bases_resultados_asignatura', 0);
    }

    public function test_paused_change_requires_confirmation_and_regenerates_atomically(): void
    {
        $saved = $this->putJson($this->url(), $this->payload())->assertOk()->json();
        $convocation = $this->convocation(generate: true);
        $original = Syllabus::query()->firstOrFail();
        $convocation->update(['estado' => Convocation::STATE_PAUSED]);
        $payload = [...$this->editPayload($saved), 'source_locator' => 'Página 142, contenido corregido'];
        $this->putJson($this->url(), $payload)->assertUnprocessable()->assertJsonValidationErrors(['purge_required', 'purge_count']);
        $this->assertDatabaseHas('silabos', ['id' => $original->id]);
        $this->getJson($this->url())->assertJsonPath('fingerprint', $saved['fingerprint']);

        $this->putJson($this->url(), [...$payload, 'confirm_purge' => true])->assertOk();
        $this->assertDatabaseMissing('silabos', ['id' => $original->id]);
        $this->assertDatabaseCount('silabos', 1);
        $this->assertSame('Página 142, contenido corregido', SubjectLearningOutcomeBase::query()->firstOrFail()->localizador_fuente);
    }

    public function test_failed_regeneration_rolls_back_master_content_and_syllabus_deletion(): void
    {
        $saved = $this->putJson($this->url(), $this->payload())->assertOk()->json();
        $convocation = $this->convocation(generate: true);
        $original = Syllabus::query()->firstOrFail();
        $convocation->update(['estado' => Convocation::STATE_PAUSED]);
        ScheduledSubject::query()->firstOrFail()->update(['activo' => false]);

        $this->putJson($this->url(), [
            ...$this->editPayload($saved), 'source_locator' => 'No debe persistir', 'confirm_purge' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('convocation');
        $this->assertDatabaseHas('silabos', ['id' => $original->id]);
        $this->getJson($this->url())->assertJsonPath('fingerprint', $saved['fingerprint']);
        $this->assertDatabaseCount('resultados_aprendizaje_asignatura', 2);
        $this->assertSame(1, AuditEvent::query()->where('accion', 'academico.resultados_aprendizaje.confirmados')->count());
    }

    public function test_corrections_preserve_approved_history_and_replace_only_work_in_progress(): void
    {
        $saved = $this->putJson($this->url(), $this->payload())->assertOk()->json();
        $convocation = $this->convocation(generate: true);
        $approved = Syllabus::query()->firstOrFail();
        $originalContext = $approved->contexto_academico;
        $snapshot = app(SyllabusSnapshot::class)->build($approved);
        $revision = SyllabusRevision::query()->create([
            'silabo_id' => $approved->id,
            'numero_revision' => 1,
            'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => $approved->version_bloqueo,
            'fotografia' => $snapshot,
            'huella_sha256' => app(CanonicalHasher::class)->hash($snapshot),
            'enviado_por' => User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail()->id,
            'enviado_en' => now(),
        ]);
        $snapshot = $revision->fresh()->fotografia;
        $approval = Approval::query()->create([
            'revision_silabo_id' => $revision->id,
            'clave_idempotencia' => (string) Str::uuid(),
            'huella_sha256' => $revision->huella_sha256,
            'aprobado_por' => $this->coordinator->id,
            'aprobado_en' => now(),
        ]);
        $approved->update(['estado' => 'aprobado']);
        $convocation->update(['estado' => Convocation::STATE_PAUSED]);

        // Solo existe el aprobado: corregir la base para futuros sílabos no exige purga.
        $payload = $this->editPayload($saved);
        $payload['outcomes'][0]['text'] = 'Aplica fundamentos de calidad a un producto de software.';
        $corrected = $this->putJson($this->url(), $payload)->assertOk()->json();
        $this->assertSame($originalContext, $approved->fresh()->contexto_academico);
        $this->assertSame($snapshot, $revision->fresh()->fotografia);
        $this->assertDatabaseHas('aprobaciones', ['id' => $approval->id, 'revision_silabo_id' => $revision->id]);

        // Un paralelo nuevo hereda la base corregida sin tocar el expediente aprobado.
        $parallel = Parallel::query()->create([
            'programacion_asignatura_id' => $approved->parallel->programacion_asignatura_id,
            'codigo' => 'B', 'jornada' => 'matutina',
        ]);
        TeacherAssignment::query()->create([
            'asignacion_rol_id' => TeacherAssignment::query()->firstOrFail()->asignacion_rol_id,
            'paralelo_id' => $parallel->id, 'activo' => true,
        ]);
        $this->assertSame(1, app(GenerateConvocationSyllabi::class)->execute($convocation));
        $inProgress = Syllabus::query()->where('paralelo_id', $parallel->id)->firstOrFail();
        $this->assertSame($payload['outcomes'][0]['text'], data_get($inProgress->contexto_academico, 'subject.learning_outcomes.outcomes.0.text'));

        // Con ambos estados presentes, únicamente el expediente en curso se reemplaza.
        $next = $this->editPayload($corrected);
        $next['outcomes'][0]['text'] = 'Evalúa productos aplicando criterios de calidad de software.';
        $this->putJson($this->url(), $next)->assertUnprocessable()->assertJsonPath('errors.purge_count.0', '1');
        $latest = $this->putJson($this->url(), [...$next, 'confirm_purge' => true])->assertOk()->json();
        $this->assertDatabaseMissing('silabos', ['id' => $inProgress->id]);
        $this->assertDatabaseHas('silabos', ['id' => $approved->id, 'estado_silabo' => 'aprobado']);
        $this->assertSame($originalContext, $approved->fresh()->contexto_academico);
        $this->assertSame($snapshot, $revision->fresh()->fotografia);
        $this->assertSame(app(CanonicalHasher::class)->hash($snapshot), $revision->fresh()->huella_sha256);
        $this->assertDatabaseHas('aprobaciones', ['id' => $approval->id, 'revision_silabo_id' => $revision->id]);
        $this->assertDatabaseCount('revisiones_silabo', 1);
        $this->assertDatabaseCount('silabos', 2);
        $replacement = Syllabus::query()->where('paralelo_id', $parallel->id)->firstOrFail();
        $this->assertSame($next['outcomes'][0]['text'], data_get($replacement->contexto_academico, 'subject.learning_outcomes.outcomes.0.text'));

        // La reapertura habilita correcciones docentes y conserva la misma protección
        // frente al borrado completo que se produce al corregir una base académica.
        $this->postJson(route('api.v1.coordination.reviews.reopen', $approved), [
            'idempotency_key' => (string) Str::uuid(),
            'cause' => 'Revisión del contenido docente después de la aprobación.',
        ])->assertOk();
        $reopening = $approved->reopenings()->firstOrFail();
        $reopenedPayload = [...$this->editPayload($latest), 'source_locator' => 'Fuente corregida después de reabrir'];
        $this->putJson($this->url(), $reopenedPayload)->assertUnprocessable()->assertJsonPath('errors.purge_count.0', '1');
        $this->putJson($this->url(), [...$reopenedPayload, 'confirm_purge' => true])->assertOk();
        $this->assertDatabaseMissing('silabos', ['id' => $replacement->id]);
        $this->assertDatabaseHas('silabos', ['id' => $approved->id, 'estado_silabo' => 'correccion_solicitada']);
        $this->assertSame($originalContext, $approved->fresh()->contexto_academico);
        $this->assertSame($snapshot, $revision->fresh()->fotografia);
        $this->assertSame(app(CanonicalHasher::class)->hash($snapshot), $revision->fresh()->huella_sha256);
        $this->assertDatabaseHas('aprobaciones', ['id' => $approval->id, 'revision_silabo_id' => $revision->id]);
        $this->assertDatabaseHas('reaperturas', ['id' => $reopening->id, 'aprobacion_id' => $approval->id]);
        $this->assertDatabaseCount('revisiones_silabo', 1);
        $this->assertDatabaseCount('silabos', 2);
    }

    public function test_paused_change_preserves_syllabi_of_other_subjects_in_the_same_career(): void
    {
        $saved = $this->putJson($this->url(), $this->payload())->assertOk()->json();
        $other = $this->subject->replicate();
        $other->codigo_asignatura = 'SW-OTRA';
        $other->save();
        $scheduled = ScheduledSubject::query()->firstOrFail()->replicate();
        $scheduled->asignatura_id = $other->id;
        $scheduled->save();
        $parallel = Parallel::query()->create([
            'programacion_asignatura_id' => $scheduled->id, 'codigo' => 'A', 'jornada' => 'matutina',
        ]);
        TeacherAssignment::query()->create([
            'asignacion_rol_id' => TeacherAssignment::query()->firstOrFail()->asignacion_rol_id,
            'paralelo_id' => $parallel->id, 'activo' => true,
        ]);
        $convocation = $this->convocation(generate: true);
        $original = Syllabus::query()->where('asignatura_id', $this->subject->id)->firstOrFail();
        $untouched = Syllabus::query()->where('asignatura_id', $other->id)->firstOrFail();
        $convocation->update(['estado' => Convocation::STATE_PAUSED]);
        // Otro paralelo aún sin docente está en preparación durante la pausa. No
        // participa en la validación ni generación de la materia que se corrige.
        $pendingParallel = Parallel::query()->create([
            'programacion_asignatura_id' => $scheduled->id, 'codigo' => 'B', 'jornada' => 'matutina',
        ]);
        $payload = [...$this->editPayload($saved), 'source_locator' => 'Corrección de una sola materia'];
        $this->putJson($this->url(), $payload)->assertUnprocessable()->assertJsonPath('errors.purge_count.0', '1');
        $this->putJson($this->url(), [...$payload, 'confirm_purge' => true])->assertOk();
        $this->assertDatabaseMissing('silabos', ['id' => $original->id]);
        $this->assertDatabaseHas('silabos', ['id' => $untouched->id]);
        $this->assertDatabaseMissing('silabos', ['paralelo_id' => $pendingParallel->id]);
        $this->assertDatabaseCount('silabos', 2);
    }

    public function test_deleting_a_subject_without_history_removes_its_owned_outcomes(): void
    {
        $subject = $this->subject->replicate();
        $subject->codigo_asignatura = 'SW-SIN-HISTORIA';
        $subject->save();
        $this->putJson($this->url($subject), $this->payload())->assertOk();
        $this->deleteJson(route('api.v1.coordination.academic.curriculum.subjects.destroy', [$subject->carrera_id, $subject->id]))->assertOk();
        $this->assertDatabaseMissing('asignaturas', ['id' => $subject->id]);
        $this->assertDatabaseCount('bases_resultados_asignatura', 0);
        $this->assertDatabaseCount('resultados_aprendizaje_asignatura', 0);
    }

    public function test_deleting_a_programmed_subject_preserves_its_confirmed_outcomes(): void
    {
        $this->putJson($this->url(), $this->payload())->assertOk();
        $this->deleteJson(route('api.v1.coordination.academic.curriculum.subjects.destroy', [$this->subject->carrera_id, $this->subject->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->assertDatabaseCount('bases_resultados_asignatura', 1);
        $this->assertDatabaseCount('resultados_aprendizaje_asignatura', 2);
    }

    private function actingAsCoordinator(): void
    {
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->role->id]);
    }

    private function url(?Subject $subject = null): string
    {
        return route('api.v1.coordination.academic.subjects.learning-outcomes.show', ($subject ?? $this->subject)->id);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'source_name' => 'Proyecto de Carrera Actualizado',
            'source_locator' => 'Página 141, columna Resultados de aprendizaje',
            'outcomes' => [
                ['text' => 'Identifica los fundamentos de la calidad del software.'],
                ['text' => 'Evalúa la calidad de un producto de software.'],
            ],
            'confirmed' => true,
            'expected_fingerprint' => null,
        ];
    }

    /** @param array<string, mixed> $saved
     * @return array<string, mixed>
     */
    private function editPayload(array $saved): array
    {
        return [
            'source_name' => $saved['source_name'], 'source_locator' => $saved['source_locator'],
            'outcomes' => array_map(fn (array $row): array => ['id' => $row['id'], 'text' => $row['text']], $saved['outcomes']),
            'expected_fingerprint' => $saved['fingerprint'], 'confirmed' => true,
        ];
    }

    private function convocation(bool $generate = false): Convocation
    {
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->firstOrFail()->id,
            'inicia_en' => now(), 'entrega_en' => now()->addMonth(), 'estado' => SyllabusProcess::STATE_OPEN,
        ]);
        $convocation = Convocation::query()->create([
            'carrera_id' => $this->subject->carrera_id, 'proceso_id' => $process->id, 'estado' => Convocation::STATE_OPEN,
        ]);
        if ($generate) {
            $this->seed(SyllabusTemplateSeeder::class);
            app(GenerateConvocationSyllabi::class)->execute($convocation);
        }

        return $convocation;
    }
}
