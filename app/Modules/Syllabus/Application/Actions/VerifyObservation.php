<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\ReviewObservation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerifyObservation
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    public function execute(ReviewObservation $observation, User $actor, Request $request): ReviewObservation
    {
        return DB::transaction(function () use ($actor, $observation, $request): ReviewObservation {
            $observedRevision = SyllabusRevision::query()->findOrFail($observation->revision_silabo_id);
            $syllabus = Syllabus::query()->lockForUpdate()->findOrFail($observedRevision->silabo_id);
            $observation = ReviewObservation::query()
                ->with('revision')
                ->lockForUpdate()
                ->findOrFail($observation->id);
            if ($syllabus->estado !== 'en_revision') {
                throw ValidationException::withMessages(['syllabus' => 'El expediente no está en revisión.']);
            }
            if ($observation->estado === 'verificada') {
                return $observation;
            }

            $latest = SyllabusRevision::query()
                ->where('silabo_id', $syllabus->id)
                ->orderByDesc('numero_revision')
                ->firstOrFail();

            $observation->update(['estado' => 'verificada']);
            $activeRole = $this->roles->resolve($request);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'silabo.observacion_verificada',
                resourceType: 'observacion_revision',
                resourceId: $observation->id,
                result: 'exito',
                metadata: ['revision_number' => $latest->numero_revision],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
            $this->notifications->execute(
                eventType: 'silabo.observacion_verificada',
                deduplicationKey: "silabo.observacion_verificada:{$observation->id}",
                recipientIds: $this->recipients->teachersFor($syllabus),
                title: 'Observación resuelta',
                message: "Coordinación dio por resuelta una observación del sílabo {$syllabus->academicSubjectName()}.",
                resourceType: 'silabo',
                resourceId: $syllabus->id,
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $observation;
        });
    }
}
