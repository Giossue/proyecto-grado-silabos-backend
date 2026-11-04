<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class ProcessReopeningTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use RefreshDatabase;
    use UsesInstitutionalDays;

    private const REOPENED = 'Plazo prorrogado. La convocatoria se volvió a abrir.';

    private const ANOTHER_IN_PROGRESS = 'Hay otra convocatoria en curso; finalícela o elimínela antes de reabrir esta';

    private User $administrator;

    private User $coordinator;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        AcademicPeriod::query()->firstOrFail()->update([
            'fecha_inicio' => $this->institutionDay(-120),
            'fecha_fin' => $this->institutionDay(60),
        ]);
    }

    public function test_finalizing_records_each_career_convocation_state_and_an_extension_restores_them(): void
    {
        [$process, $paused, $open, $draft] = $this->processWithTwoCareers();

        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertNotNull(DB::table('convocatorias_universidad')->where('convocatoria_universidad_id', $process->id)->value('finalizada_en'));
        $this->assertSame(['cerrada', 'pausada'], $this->careerState($paused));
        $this->assertSame(['cerrada', 'abierta'], $this->careerState($open));
        $this->assertArrayHasKey('closed_document', $draft->fresh()?->contexto_academico ?? []);
        $this->processList()->assertJsonPath('processes.0.extension_block_reason', null);

        $this->extend($process, $this->institutionDay(20))->assertOk()->assertExactJson(['message' => self::REOPENED]);

        $reopened = $process->fresh();
        $this->assertSame(SyllabusProcess::STATE_OPEN, $reopened?->estado);
        $this->assertSame($this->institutionDay(20), InstitutionDate::dateOf($reopened->entrega_en));
        $this->assertSame(1, $reopened->cantidad_prorrogas);
        $this->assertNull($reopened->pausado_en);
        $this->assertNull(DB::table('convocatorias_universidad')->where('convocatoria_universidad_id', $process->id)->value('finalizada_en'));
        $this->assertSame(['pausada', null], $this->careerState($paused));
        $this->assertSame(['abierta', null], $this->careerState($open));
        $this->assertArrayNotHasKey('closed_document', $draft->fresh()?->contexto_academico ?? []);
        $this->assertSame('borrador', $draft->fresh()?->estado);
        $reopenAudit = AuditEvent::query()->where('accion', 'proceso_silabos.reabierto')->where('recurso_id', $process->id)->sole();
        $this->assertSame($this->administrator->id, $reopenAudit->actor_usuario_id);
        $this->assertSame(['previous_state' => SyllabusProcess::STATE_CLOSED], $reopenAudit->metadatos);
        $extension = AuditEvent::query()->where('accion', 'proceso_silabos.plazo_extendido')->where('recurso_id', $process->id)->sole();
        $this->assertSame(1, $extension->metadatos['extension_number']);
        $this->assertSame(1, InternalNotification::query()->where('usuario_id', $this->teacher->id)->where('tipo', 'proceso.plazo_extendido')->count());
        $this->processList()->assertJsonPath('processes.0.state', SyllabusProcess::STATE_OPEN)
            ->assertJsonPath('processes.0.deletable', true);
    }

    public function test_an_extension_reopens_a_process_the_system_finalized_and_the_teacher_can_edit_again(): void
    {
        [$process, , , $draft] = $this->processWithTwoCareers(pauseFirst: false);
        $newDeadline = $this->institutionDay(20);

        $this->travelTo(InstitutionDate::startOfDay($this->institutionDay(11))->addMinutes(10));
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 1.')->assertSuccessful();
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $draft))->assertOk()
            ->assertJsonPath('syllabus.editable', false);

        $this->extend($process, $newDeadline)->assertOk()->assertExactJson(['message' => self::REOPENED]);

        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);
        $this->assertSame(Convocation::STATE_OPEN, $draft->convocation()->value('estado_convocatoria_carrera'));
        $this->assertArrayNotHasKey('closed_document', $draft->fresh()?->contexto_academico ?? []);
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $draft))->assertOk()
            ->assertJsonPath('syllabus.editable', true);
        $this->assertSame(1, AuditEvent::query()->where('accion', 'proceso_silabos.reabierto')->count());
    }

    public function test_a_finalized_process_is_not_reopened_while_another_one_is_in_progress(): void
    {
        [$process] = $this->processWithTwoCareers();
        $this->transition($process, 'cerrar')->assertSuccessful();
        $other = SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->create([
                'codigo' => '2027-2028',
                'fecha_inicio' => $this->institutionDay(61),
                'fecha_fin' => $this->institutionDay(200),
                'semanas_lectivas' => 16,
            ])->id,
            'inicia_en' => InstitutionDate::startOfDay($this->institutionDay(61)),
            'entrega_en' => InstitutionDate::endOfDay($this->institutionDay(90)),
            'estado' => SyllabusProcess::STATE_PAUSED,
        ]);
        $stored = $this->storedWork();

        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['process' => self::ANOTHER_IN_PROGRESS]);

        $this->assertSame($stored, $this->storedWork());
        $reasons = collect($this->processList()->json('processes'))->pluck('extension_block_reason', 'id');
        $this->assertSame(self::ANOTHER_IN_PROGRESS, $reasons[$process->id]);
        $this->assertNull($reasons[$other->id]);
    }

    public function test_a_finalized_process_keeps_the_extension_limits(): void
    {
        [$process, $paused, , $draft] = $this->processWithTwoCareers();
        $this->transition($process, 'cerrar')->assertSuccessful();
        $limit = CarbonImmutable::parse($this->institutionDay(60))->format('d/m/Y');
        $current = CarbonImmutable::parse($this->institutionDay(10))->format('d/m/Y');
        $stored = $this->storedWork();

        $this->extend($process, $this->institutionDay(-1))->assertUnprocessable()
            ->assertJsonValidationErrors(['due_at' => 'La nueva fecha ya pasó.']);
        $this->extend($process, $this->institutionDay(10))->assertUnprocessable()
            ->assertJsonValidationErrors(['due_at' => "La nueva fecha debe ser posterior a la vigente, que es el {$current}."]);
        $this->extend($process, $this->institutionDay(61))->assertUnprocessable()
            ->assertJsonValidationErrors(['due_at' => "La nueva fecha no puede pasar del último día de clases ({$limit})."]);

        $this->assertSame($stored, $this->storedWork());
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertSame(['cerrada', 'pausada'], $this->careerState($paused));
        $this->assertArrayHasKey('closed_document', $draft->fresh()?->contexto_academico ?? []);
    }

    public function test_a_finalized_process_without_extensions_left_is_not_reopened(): void
    {
        [$process] = $this->processWithTwoCareers();
        $this->extend($process, $this->institutionDay(20))->assertOk()
            ->assertExactJson(['message' => 'Plazo prorrogado para todas las convocatorias del proceso.']);
        $this->extend($process, $this->institutionDay(30))->assertOk();
        $this->transition($process, 'cerrar')->assertSuccessful();
        $stored = $this->storedWork();

        $this->extend($process, $this->institutionDay(40))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['process' => 'Ya se usaron las 2 prórrogas de esta convocatoria.']);

        $this->assertSame($stored, $this->storedWork());
        $this->processList()->assertJsonPath('processes.0.extension_block_reason', 'Ya se usaron las 2 prórrogas de esta convocatoria.');
    }

    public function test_reopening_after_a_template_change_asks_to_confirm_and_then_adapts_the_syllabi(): void
    {
        [$process, , $open, $draft] = $this->processWithTwoCareers(pauseFirst: false);
        $approved = $this->approvedSyllabus($draft);
        $this->transition($process, 'cerrar')->assertSuccessful();
        $this->changeTemplate();
        $stored = $this->storedWork();

        $this->extend($process, $this->institutionDay(20))
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'La plantilla cambió después de finalizar. Confirme para adaptar los sílabos del período: se conserva lo escrito y los enviados o aprobados vuelven a borrador.',
                'code' => 'plantilla_cambiada',
                'counts' => ['sin_iniciar' => 0, 'borrador' => 1, 'en_revision' => 0, 'correccion_solicitada' => 0, 'aprobado' => 1],
            ]);
        $this->assertSame($stored, $this->storedWork());
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);

        $this->extend($process, $this->institutionDay(20), ['confirmar_rehacer' => true])
            ->assertOk()->assertExactJson(['message' => self::REOPENED]);

        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);
        $this->assertSame(['abierta', null], $this->careerState($open));
        $this->assertSame('borrador', $approved->fresh()?->estado);
        $this->assertDatabaseMissing('revisiones_silabo', ['silabo_id' => $approved->id]);
        $this->assertArrayNotHasKey('closed_document', $draft->fresh()?->contexto_academico ?? []);
        $adapted = AuditEvent::query()->where('accion', 'proceso.plantilla_rehecho')->where('recurso_id', $process->id)->sole();
        $this->assertSame(1, $adapted->metadatos['estado_borrador']);
        $this->assertSame(1, $adapted->metadatos['estado_aprobado']);
        $this->assertTrue(InternalNotification::query()->where('usuario_id', $this->teacher->id)->where('tipo', 'proceso.plantilla_rehecho')->exists());
    }

    public function test_a_template_change_made_during_a_pause_that_ended_in_finalizing_is_also_confirmed(): void
    {
        [$process] = $this->processWithTwoCareers(pauseFirst: false);
        $this->transition($process, 'pausar')->assertSuccessful();
        $this->changeTemplate();
        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->extend($process, $this->institutionDay(20))->assertStatus(422)->assertJsonPath('code', 'plantilla_cambiada');
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
    }

    public function test_a_template_change_already_adapted_when_resuming_does_not_ask_again_on_reopening(): void
    {
        [$process] = $this->processWithTwoCareers(pauseFirst: false);
        $this->transition($process, 'pausar')->assertSuccessful();
        $this->changeTemplate();
        $this->transition($process, 'reanudar', ['confirmar_rehacer' => true])->assertSuccessful();
        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->extend($process, $this->institutionDay(20))->assertOk()->assertExactJson(['message' => self::REOPENED]);
        $this->assertSame(1, AuditEvent::query()->where('accion', 'proceso.plantilla_rehecho')->count());
    }

    public function test_only_administration_reopens_a_finalized_process(): void
    {
        [$process] = $this->processWithTwoCareers();
        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->actingAs($this->coordinator)
            ->withSession(['active_role_assignment_id' => $this->coordinator->roleAssignments()->firstOrFail()->id])
            ->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => $this->institutionDay(20)])
            ->assertForbidden();
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
    }

    /** @return array{SyllabusProcess, Convocation, Convocation, Syllabus} */
    private function processWithTwoCareers(bool $pauseFirst = true): array
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->fillSubjectProfiles();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            'period_id' => AcademicPeriod::query()->valueOrFail('id'),
            'starts_at' => $this->institutionDay(),
            'due_at' => $this->institutionDay(10),
        ])->assertSuccessful();
        $process = SyllabusProcess::query()->firstOrFail();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), ['process_id' => $process->id])->assertSuccessful();
        $first = Convocation::query()->where('proceso_id', $process->id)->firstOrFail();
        $draft = Syllabus::query()->where('convocatoria_id', $first->id)->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $draft))->assertSuccessful();
        if ($pauseFirst) {
            $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$first, 'pausar']))->assertSuccessful();
        }
        $career = Career::query()->create([
            'facultad_id' => Career::query()->valueOrFail('facultad_id'),
            'codigo_carrera' => 'REDES',
            'nombre' => 'Redes',
            'modalidad' => StudyModality::Presencial,
            'activo' => true,
            'cantidad_ciclos_malla' => 8,
        ]);
        $second = Convocation::query()->create(['carrera_id' => $career->id, 'proceso_id' => $process->id, 'estado' => Convocation::STATE_OPEN]);

        return [$process->fresh() ?? $process, $first, $second, $draft->fresh() ?? $draft];
    }

    private function approvedSyllabus(Syllabus $draft): Syllabus
    {
        $parallel = $draft->parallel->replicate();
        $parallel->codigo = 'APROBADO';
        $parallel->save();
        $approved = Syllabus::query()->create([
            'convocatoria_id' => $draft->convocatoria_id,
            'paralelo_id' => $parallel->id,
            'contexto_academico' => $draft->contexto_academico,
            'estado' => 'aprobado',
        ]);
        $revision = SyllabusRevision::query()->create([
            'silabo_id' => $approved->id,
            'numero_revision' => 1,
            'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => 1,
            'fotografia' => $draft->contexto_academico,
            'huella_sha256' => str_repeat('a', 64),
            'enviado_por' => $this->teacher->id,
            'enviado_en' => now(),
        ]);
        Approval::query()->create([
            'revision_silabo_id' => $revision->id,
            'clave_idempotencia' => (string) Str::uuid(),
            'huella_sha256' => str_repeat('a', 64),
            'aprobado_por' => $this->coordinator->id,
            'aprobado_en' => now(),
        ]);

        return $approved;
    }

    private function changeTemplate(): void
    {
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.structure.sections.store', $template), [
            'title' => 'Anexos',
            'key' => 'anexos',
            'first_field_label' => 'Anexo',
            'first_field_key' => 'anexo',
            'first_field_content_type' => 'free_text',
        ])->assertSuccessful();
        $section = $template->sections()->where('clave', 'anexos')->firstOrFail();
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.template.structure.sections.destroy', ['template' => $template, 'section' => $section]))
            ->assertSuccessful();
    }

    /** @return array{mixed, mixed} */
    private function careerState(Convocation $convocation): array
    {
        $row = DB::table('convocatorias_carreras')->where('convocatoria_carrera_id', $convocation->id)->first();

        return [$row?->estado_convocatoria_carrera, $row?->estado_previo_cierre_convocatoria_carrera];
    }

    private function storedWork(): string
    {
        $tables = ['convocatorias_universidad', 'convocatorias_carreras', 'silabos', 'revisiones_silabo', 'aprobaciones', 'valores_campo', 'notificaciones', 'eventos_auditoria'];

        return md5((string) json_encode(collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->get()->map(fn (object $row): string => (string) json_encode((array) $row))->sort()->values()->all(),
        ])->all()));
    }

    /** @param  array<string, mixed>  $extra */
    private function extend(SyllabusProcess $process, string $day, array $extra = []): TestResponse
    {
        return $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => $day, ...$extra]);
    }

    /** @param  array<string, mixed>  $payload */
    private function transition(SyllabusProcess $process, string $transition, array $payload = []): TestResponse
    {
        return $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, $transition]), $payload);
    }

    private function processList(): TestResponse
    {
        return $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.index'))->assertOk();
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)->withSession(['active_role_assignment_id' => $this->administrator->roleAssignments()->firstOrFail()->id]);

        return $this;
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->coordinator->roleAssignments()->firstOrFail()->id]);

        return $this;
    }

    private function actingAsTeacher(): static
    {
        $this->actingAs($this->teacher)->withSession(['active_role_assignment_id' => $this->teacher->roleAssignments()->firstOrFail()->id]);

        return $this;
    }
}
