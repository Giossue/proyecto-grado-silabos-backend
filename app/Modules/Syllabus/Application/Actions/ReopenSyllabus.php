<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReopenSyllabus
{
    public function __construct(
        private readonly SyllabusSnapshot $snapshots,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    public function execute(
        Syllabus $syllabus,
        string $cause,
        string $idempotencyKey,
        User $actor,
        Request $request,
    ): Approval {
        $existing = $this->existingReopening($syllabus, $idempotencyKey, $actor);
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($actor, $cause, $idempotencyKey, $request, $syllabus): Approval {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $existing = $this->existingReopening($locked, $idempotencyKey, $actor);
            if ($existing !== null) {
                return $existing;
            }
            if ($locked->estado !== 'aprobado') {
                throw ValidationException::withMessages(['syllabus' => 'Solo puede reabrirse un sílabo aprobado.']);
            }

            $approval = Approval::query()
                ->with('revision')
                ->whereHas('revision', fn ($revision) => $revision->where('silabo_id', $locked->id))
                ->latest('aprobado_en')
                ->lockForUpdate()
                ->firstOrFail();
            if ($approval->isReopened()) {
                throw ValidationException::withMessages(['approval' => 'Esta aprobación ya fue reabierta.']);
            }

            $activeRole = $this->roles->resolve($request);
            $approval->update([
                'clave_reapertura' => $idempotencyKey,
                'causa_reapertura' => $cause,
                'origen_reapertura' => $activeRole?->role->codigo_rol === RoleCode::Dean->value ? Approval::ORIGIN_DEANERY : Approval::ORIGIN_COORDINATION,
                'reabierta_por' => $actor->id,
                'reabierta_en' => now(),
            ]);
            $this->snapshots->restore($locked, $approval->revision->fotografia);
            $locked->update([
                'estado' => 'correccion_solicitada',
                'version_bloqueo' => $locked->version_bloqueo + 1,
                'guardado_en' => now(),
            ]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'silabo.reabierto',
                resourceType: 'aprobacion',
                resourceId: $approval->id,
                result: 'exito',
                metadata: ['revision_number' => $approval->revision->numero_revision, 'from_state' => 'aprobado', 'to_state' => 'correccion_solicitada', 'approval_id' => $approval->id],
            );
            $this->notifications->execute(
                syllabus: $locked,
                eventType: 'silabo.reabierto',
                deduplicationKey: "silabo.reabierto:{$approval->id}",
                recipientIds: $this->recipients->teachersFor($locked),
                revisionNumber: $approval->revision->numero_revision,
            );

            return $approval;
        });
    }

    private function existingReopening(Syllabus $syllabus, string $key, User $actor): ?Approval
    {
        $reopening = $syllabus->reopenedApprovals()
            ->where('aprobaciones.clave_reapertura', $key)
            ->first();
        if ($reopening !== null && $reopening->reabierta_por !== $actor->id) {
            abort(403);
        }

        return $reopening;
    }
}
