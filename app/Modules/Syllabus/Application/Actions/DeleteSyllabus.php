<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteSyllabus
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly InProgressWork $work,
    ) {}

    public function execute(Syllabus $syllabus, User $actor, Request $request, string $reason): Syllabus
    {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Coordinator->value
            || $activeRole->carrera_id !== $syllabus->convocation()->value('carrera_id')) {
            abort(403);
        }

        return DB::transaction(function () use ($activeRole, $actor, $syllabus, $reason): Syllabus {
            $locked = Syllabus::query()
                ->lockForUpdate()
                ->with(['convocation', 'parallel'])
                ->findOrFail($syllabus->id);

            if ($locked->estado !== 'aprobado') {
                throw ValidationException::withMessages([
                    'syllabus' => 'Solo se elimina un sílabo aprobado; los demás se reinician.',
                ]);
            }
            if (! in_array($locked->convocation->estado, [Convocation::STATE_OPEN, Convocation::STATE_PAUSED], true)) {
                throw ValidationException::withMessages([
                    'syllabus' => 'La convocatoria ya no admite cambios en sus expedientes.',
                ]);
            }

            $deleted = [
                'previous_syllabus_id' => $locked->id,
                'paralelo_id' => $locked->paralelo_id,
                'revisions' => $locked->revisions()->count(),
                'reason' => $reason,
            ];
            $teachers = $this->recipients->teachersFor($locked);
            $fresh = $this->work->restartSyllabus($locked);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'silabo.eliminado',
                resourceType: 'silabo',
                resourceId: $fresh->id,
                result: 'exito',
                metadata: $deleted,
            );
            $this->notifications->execute(
                syllabus: $fresh,
                eventType: 'silabo.eliminado',
                deduplicationKey: 'silabo.eliminado:'.$fresh->id,
                recipientIds: $teachers,
                revisionNumber: null,
                reason: $reason,
            );

            return $fresh;
        });
    }
}
