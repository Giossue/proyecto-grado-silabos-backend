<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Relevar a un docente son dos cosas que deben ocurrir juntas: finalizar su asignación y
 * abrir la del reemplazo sobre los mismos paralelos. Hacerlas por separado deja una ventana en
 * la que el expediente no tiene responsable, y la apertura de convocatoria ya rechaza los
 * paralelos sin docente vigente.
 *
 * Qué pasa con el contenido depende del estado, según las decisiones A1 y DT-08:
 *
 * - Aprobado: se reabre conservando la revisión intacta. El que entra hereda el trabajo.
 * - Corrección solicitada: se conserva. Hubo envío, así que es evidencia institucional.
 * - Borrador sin enviar: se descarta y el nuevo empieza limpio.
 * - En revisión: no se traspasa. Un coordinador está evaluando contenido que envió una
 *   persona concreta; cambiarla a mitad deja la revisión sin interlocutor.
 */
class TransferSyllabusTeacher
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly ReopenSyllabus $reopen,
        private readonly RecordAuditEvent $audit,
        private readonly AcademicPeriodPlanning $periodPlanning,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    public function execute(
        Syllabus $syllabus,
        string $outgoingUserId,
        string $incomingUserId,
        string $idempotencyKey,
        User $actor,
        Request $request,
        bool $notify = true,
    ): Syllabus {
        $activeRole = $this->roles->resolve($request);
        $careerId = $syllabus->convocation()->value('carrera_id');
        if ($activeRole?->role->codigo_rol !== RoleCode::Coordinator->value
            || $activeRole->carrera_id !== $careerId) {
            abort(403);
        }
        if ($outgoingUserId === $incomingUserId) {
            throw ValidationException::withMessages([
                'incoming_user_id' => 'El docente entrante debe ser distinto del saliente.',
            ]);
        }

        $incoming = User::query()->where('activo', true)->find($incomingUserId);
        if ($incoming === null) {
            throw ValidationException::withMessages([
                'incoming_user_id' => 'La cuenta del docente entrante no existe o está inactiva.',
            ]);
        }
        $incomingRole = $this->teacherRoleInCareer($incomingUserId, (string) $careerId);
        if (! $incomingRole instanceof RoleAssignment) {
            throw ValidationException::withMessages([
                'incoming_user_id' => 'El docente entrante no tiene un rol docente vigente en la carrera.',
            ]);
        }

        return DB::transaction(function () use (
            $activeRole, $actor, $idempotencyKey, $incoming, $incomingRole, $incomingUserId, $notify, $outgoingUserId, $request, $syllabus,
        ): Syllabus {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);

            if ($locked->estado === 'en_revision') {
                throw ValidationException::withMessages([
                    'syllabus' => 'El expediente está en revisión. Resuelva la revisión antes de relevar al docente.',
                ]);
            }

            $collaborations = SyllabusCollaborator::query()
                ->with('teacherAssignment')
                ->where('silabo_id', $locked->id)
                ->where('usuario_id', $outgoingUserId)
                ->get();
            if ($collaborations->isEmpty()) {
                throw ValidationException::withMessages([
                    'outgoing_user_id' => 'Ese docente no consta como responsable de este expediente.',
                ]);
            }

            $incomingConflicts = TeacherAssignment::query()
                ->whereIn('paralelo_id', $collaborations->map(
                    fn (SyllabusCollaborator $collaboration): string => $collaboration->teacherAssignment->paralelo_id,
                ))
                ->where('activo', true)
                ->whereNotIn('id', $collaborations->pluck('docente_paralelo_id'))
                ->lockForUpdate()
                ->pluck('paralelo_id');
            if ($incomingConflicts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'incoming_user_id' => 'El docente entrante ya está asignado a uno de los paralelos de este sílabo.',
                ]);
            }

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

            $previousState = $locked->estado;
            if ($previousState === 'aprobado') {
                // Se reabre después de mover a los colaboradores, para que el aviso de
                // reapertura llegue a quien entra y no a quien acaba de salir. La revisión
                // aprobada queda intacta: ADR-0005.
                $this->reopen->execute(
                    $locked,
                    'Relevo de docente registrado por Coordinación.',
                    $idempotencyKey,
                    $actor,
                    $request,
                );
                $locked->refresh();
            }

            if ($previousState === 'borrador') {
                // DT-08: el borrador sin enviar no es evidencia institucional.
                FieldValue::query()->where('silabo_id', $locked->id)->delete();
                RepeatableRow::query()->where('silabo_id', $locked->id)->delete();
                $locked->update([
                    'estado' => 'sin_iniciar',
                    'version_bloqueo' => $locked->version_bloqueo + 1,
                    'iniciado_en' => null,
                    'guardado_en' => null,
                ]);
            }

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'silabo.docente_transferido',
                resourceType: 'silabo',
                resourceId: $locked->id,
                result: 'exito',
                metadata: [
                    'outgoing_user_id' => $outgoingUserId,
                    'incoming_user_id' => $incomingUserId,
                    'previous_state' => $previousState,
                    'parallels_moved' => $collaborations->count(),
                ],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
            if ($notify) {
                $this->notifyTeachers($locked, $outgoingUserId, $incoming, $idempotencyKey, $request);
            }

            return $locked->refresh();
        });
    }

    private function notifyTeachers(Syllabus $syllabus, string $outgoingUserId, User $incoming, string $idempotencyKey, Request $request): void
    {
        $subject = $syllabus->academicSubjectName();
        $correlationId = $request->attributes->getString('correlation_id') ?: null;
        $this->notifications->execute(
            eventType: 'silabo.docente_asignado',
            deduplicationKey: "silabo.docente_asignado:{$syllabus->id}:{$idempotencyKey}",
            recipientIds: [$incoming->id],
            title: 'Se le asignó un sílabo',
            message: "Coordinación le asignó el sílabo de {$subject}.",
            resourceType: 'silabo',
            resourceId: $syllabus->id,
            correlationId: $correlationId,
        );
        $this->notifications->execute(
            eventType: 'silabo.docente_retirado',
            deduplicationKey: "silabo.docente_retirado:{$syllabus->id}:{$idempotencyKey}",
            recipientIds: [$outgoingUserId],
            title: 'Sílabo reasignado',
            message: "El sílabo de {$subject} pasó a {$incoming->nombre}.",
            resourceType: 'silabo',
            resourceId: $syllabus->id,
            correlationId: $correlationId,
        );
    }

    private function teacherRoleInCareer(string $userId, string $careerId): ?RoleAssignment
    {
        return RoleAssignment::query()
            ->where('usuario_id', $userId)
            ->where('carrera_id', $careerId)
            ->where('rol', RoleCode::Teacher->value)
            ->whereHas('user', fn (Builder $query) => $query
                ->where('activo', true)
                ->whereNotNull('correo_verificado_en'))
            ->effective()
            ->first();
    }
}
