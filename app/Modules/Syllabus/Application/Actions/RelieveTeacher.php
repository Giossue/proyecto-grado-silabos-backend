<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\RepeatableRow;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusCollaborator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Única forma de cambiar al docente de un paralelo (I-39). Alcance: todos los paralelos
 * vigentes del saliente en la carrera o uno solo (`teacherAssignmentId`). Los paralelos
 * sin sílabo solo cambian de manos; los que tienen sílabo exigen convocatoria abierta o
 * pausada y siguen las reglas por estado (A1, DT-08):
 *
 * - Aprobado: se reabre conservando la revisión intacta. El que entra hereda el trabajo.
 * - Corrección solicitada: se conserva. Hubo envío, así que es evidencia institucional.
 * - Borrador sin enviar: se descarta y el nuevo empieza limpio.
 * - En revisión: no se traspasa; el revisor quedaría sin interlocutor.
 *
 * Todo o nada: si algo impide un paralelo, no se mueve ninguno y se dice cuál.
 *
 * @phpstan-type Summary array{parallels: int, syllabi: int}
 */
class RelieveTeacher
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly ReopenSyllabus $reopen,
        private readonly RecordAuditEvent $audit,
        private readonly AcademicPeriodPlanning $periodPlanning,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    /**
     * @return Summary
     */
    public function execute(
        string $outgoingUserId,
        string $incomingUserId,
        string $idempotencyKey,
        User $actor,
        Request $request,
        ?string $teacherAssignmentId = null,
    ): array {
        $activeRole = $this->roles->resolve($request);
        $careerId = $activeRole?->carrera_id;
        if ($activeRole?->role->codigo_rol !== RoleCode::Coordinator->value || $careerId === null) {
            abort(403);
        }
        if ($outgoingUserId === $incomingUserId) {
            throw ValidationException::withMessages(['incoming_user_id' => 'El docente entrante debe ser distinto del saliente.']);
        }
        $incomingRole = $this->teacherRoleInCareer($incomingUserId, $careerId);
        if (! $incomingRole instanceof RoleAssignment) {
            throw ValidationException::withMessages(['incoming_user_id' => 'El docente entrante no tiene un rol docente vigente en la carrera.']);
        }

        return DB::transaction(function () use ($activeRole, $actor, $careerId, $idempotencyKey, $incomingRole, $incomingUserId, $outgoingUserId, $request, $teacherAssignmentId): array {
            $assignments = TeacherAssignment::query()
                ->forUser($outgoingUserId)
                ->where('activo', true)
                ->whereHas('parallel.scheduledSubject.subject', fn ($query) => $query->where('carrera_id', $careerId))
                ->when($teacherAssignmentId !== null, fn ($query) => $query->whereKey($teacherAssignmentId))
                ->with([
                    'parallel.scheduledSubject.subject:id,nombre',
                    'parallel.scheduledSubject.academicPeriod:id,fecha_inicio,fecha_fin',
                ])
                ->lockForUpdate()
                ->get()
                ->filter(fn (TeacherAssignment $assignment): bool => $this->periodPlanning
                    ->mayPlan($assignment->parallel->scheduledSubject->academicPeriod))
                ->values();
            if ($assignments->isEmpty()) {
                throw ValidationException::withMessages($teacherAssignmentId !== null
                    ? ['teacher_assignment_id' => 'Ese paralelo no está vigente a cargo del docente saliente.']
                    : ['outgoing_user_id' => 'Ese docente no tiene paralelos vigentes en la carrera.']);
            }

            $incomingConflicts = TeacherAssignment::query()
                ->whereIn('paralelo_id', $assignments->pluck('paralelo_id'))
                ->where('activo', true)
                ->whereNotIn('id', $assignments->pluck('id'))
                ->lockForUpdate()
                ->pluck('paralelo_id');
            if ($incomingConflicts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'incoming_user_id' => 'El docente entrante ya está asignado a uno de los paralelos que se intentan relevar.',
                ]);
            }

            $collaborations = SyllabusCollaborator::query()
                ->whereIn('docente_paralelo_id', $assignments->pluck('id'))
                ->with(['syllabus:id,estado,asignatura_id,convocatoria_id', 'syllabus.convocation:id,estado', 'teacherAssignment'])
                ->get();
            $underReview = $collaborations
                ->filter(fn (SyllabusCollaborator $collaboration): bool => $collaboration->syllabus->estado === 'en_revision')
                ->map(fn (SyllabusCollaborator $collaboration): string => $assignments
                    ->firstWhere('id', $collaboration->docente_paralelo_id)?->parallel->scheduledSubject->subject->nombre ?? 'una materia');
            if ($underReview->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'outgoing_user_id' => 'Hay sílabos en revisión ('.$underReview->unique()->implode(', ').'). Resuélvalos antes de relevar.',
                ]);
            }

            $notOpen = $collaborations
                ->filter(fn (SyllabusCollaborator $collaboration): bool => ! in_array($collaboration->syllabus->convocation->estado, ['abierta', 'pausada'], true))
                ->map(fn (SyllabusCollaborator $collaboration): string => $assignments
                    ->firstWhere('id', $collaboration->docente_paralelo_id)?->parallel->scheduledSubject->subject->nombre ?? 'una materia');
            if ($notOpen->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'outgoing_user_id' => 'La convocatoria de '.$notOpen->unique()->implode(', ').' no está abierta ni pausada. Solo se releva con sílabos en una convocatoria vigente.',
                ]);
            }

            $syllabusIds = $collaborations->pluck('silabo_id')->unique();
            // Se toma antes de mover: mover reescribe `docente_paralelo_id` en cada colaboración.
            $assignmentsWithSyllabus = $collaborations->pluck('docente_paralelo_id')->all();
            foreach (Syllabus::query()->whereIn('id', $syllabusIds)->lockForUpdate()->get() as $syllabus) {
                $this->moveSyllabus(
                    $syllabus,
                    $collaborations->where('silabo_id', $syllabus->id)->values(),
                    $incomingRole,
                    $outgoingUserId,
                    $incomingUserId,
                    Uuid::uuid5(Uuid::NAMESPACE_URL, "relevo:{$idempotencyKey}:{$syllabus->id}")->toString(),
                    $actor,
                    $activeRole,
                    $request,
                );
            }

            $movedWithoutSyllabus = 0;
            foreach ($assignments as $assignment) {
                if (in_array($assignment->id, $assignmentsWithSyllabus, true)) {
                    continue;
                }
                $assignment->update(['activo' => false]);
                TeacherAssignment::query()->create([
                    'asignacion_rol_id' => $incomingRole->id,
                    'paralelo_id' => $assignment->paralelo_id,
                    'activo' => true,
                ]);
                $movedWithoutSyllabus++;
            }

            $summary = ['parallels' => $assignments->count(), 'syllabi' => $syllabusIds->count()];
            $scope = $teacherAssignmentId !== null ? ['teacher_assignment_id' => $teacherAssignmentId] : [];
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'docente.relevo_global',
                resourceType: 'usuario',
                resourceId: $outgoingUserId,
                result: 'exito',
                metadata: [...$summary, 'incoming_user_id' => $incomingUserId, 'parallels_without_syllabus' => $movedWithoutSyllabus, 'career_id' => $careerId, ...$scope],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
            $this->notifyTeachers($careerId, $outgoingUserId, $incomingUserId, $summary, $idempotencyKey, $request);

            return $summary;
        });
    }

    /**
     * Pasa al entrante los paralelos del saliente en un sílabo y aplica la regla por estado.
     *
     * @param  Collection<int, SyllabusCollaborator>  $collaborations
     */
    private function moveSyllabus(
        Syllabus $syllabus,
        Collection $collaborations,
        RoleAssignment $incomingRole,
        string $outgoingUserId,
        string $incomingUserId,
        string $idempotencyKey,
        User $actor,
        RoleAssignment $activeRole,
        Request $request,
    ): void {
        foreach ($collaborations as $collaboration) {
            $previous = $collaboration->teacherAssignment;
            $this->periodPlanning->assertTeacherAssignmentMayChange($previous, 'syllabus');
            $previous->update(['activo' => false]);
            $replacement = TeacherAssignment::query()->create([
                'asignacion_rol_id' => $incomingRole->id,
                'paralelo_id' => $previous->paralelo_id,
                'activo' => true,
            ]);
            $collaboration->update([
                'usuario_id' => $incomingUserId,
                'docente_paralelo_id' => $replacement->id,
            ]);
        }

        $previousState = $syllabus->estado;
        if ($previousState === 'aprobado') {
            // Se reabre después de mover a los colaboradores, para que el aviso llegue a
            // quien entra. La revisión aprobada queda intacta: ADR-0005.
            $this->reopen->execute($syllabus, 'Relevo de docente registrado por Coordinación.', $idempotencyKey, $actor, $request);
        }
        if ($previousState === 'borrador') {
            // DT-08: el borrador sin enviar no es evidencia institucional.
            FieldValue::query()->where('silabo_id', $syllabus->id)->delete();
            RepeatableRow::query()->where('silabo_id', $syllabus->id)->delete();
            $syllabus->update([
                'estado' => 'sin_iniciar',
                'version_bloqueo' => $syllabus->version_bloqueo + 1,
                'iniciado_en' => null,
                'guardado_en' => null,
            ]);
        }

        $this->audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $activeRole->id,
            action: 'silabo.docente_transferido',
            resourceType: 'silabo',
            resourceId: $syllabus->id,
            result: 'exito',
            metadata: [
                'outgoing_user_id' => $outgoingUserId,
                'incoming_user_id' => $incomingUserId,
                'previous_state' => $previousState,
                'parallels_moved' => $collaborations->count(),
            ],
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );
    }

    /** @param Summary $summary */
    private function notifyTeachers(string $careerId, string $outgoingUserId, string $incomingUserId, array $summary, string $idempotencyKey, Request $request): void
    {
        $names = User::query()->whereIn('id', [$outgoingUserId, $incomingUserId])->pluck('nombre', 'id');
        $career = Career::query()->whereKey($careerId)->value('nombre');
        $parallels = $summary['parallels'] === 1 ? '1 paralelo' : "{$summary['parallels']} paralelos";
        $syllabi = match ($summary['syllabi']) {
            0 => '',
            1 => ' y 1 sílabo',
            default => " y {$summary['syllabi']} sílabos",
        };
        $correlationId = $request->attributes->getString('correlation_id') ?: null;

        $this->notifications->execute(
            eventType: 'docente.relevo_recibido',
            deduplicationKey: "docente.relevo_recibido:{$idempotencyKey}",
            recipientIds: [$incomingUserId],
            title: 'Recibió paralelos de otro docente',
            message: "Coordinación de {$career} le asignó {$parallels}{$syllabi} que tenía {$names[$outgoingUserId]}.",
            resourceType: 'carrera',
            resourceId: $careerId,
            correlationId: $correlationId,
        );
        $this->notifications->execute(
            eventType: 'docente.relevo_entregado',
            deduplicationKey: "docente.relevo_entregado:{$idempotencyKey}",
            recipientIds: [$outgoingUserId],
            title: 'Paralelos reasignados',
            message: "Coordinación de {$career} asignó sus {$parallels}{$syllabi} a {$names[$incomingUserId]}.",
            resourceType: 'carrera',
            resourceId: $careerId,
            correlationId: $correlationId,
        );
    }

    private function teacherRoleInCareer(string $userId, string $careerId): ?RoleAssignment
    {
        return RoleAssignment::query()
            ->effective()
            ->where('usuario_id', $userId)
            ->where('carrera_id', $careerId)
            ->where('rol', RoleCode::Teacher->value)
            ->whereHas('user', fn (Builder $query) => $query
                ->where('activo', true)
                ->whereNotNull('correo_verificado_en'))
            ->first();
    }
}
