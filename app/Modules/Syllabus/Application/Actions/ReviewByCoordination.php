<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CoordinationReview;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\ReviewObservation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Coordinación aprueba la revisión tras biblioteca y la pasa al decanato (I-173). */
class ReviewByCoordination
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    public function execute(SyllabusRevision $revision, User $actor, Request $request): CoordinationReview
    {
        return DB::transaction(function () use ($actor, $request, $revision): CoordinationReview {
            $syllabus = Syllabus::query()->lockForUpdate()->findOrFail($revision->silabo_id);
            $existing = CoordinationReview::query()->where('revision_silabo_id', $revision->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $latestId = SyllabusRevision::query()->where('silabo_id', $syllabus->id)->orderByDesc('numero_revision')->value('id');
            if ($syllabus->estado !== 'en_revision' || $latestId !== $revision->id || ReviewStage::ofRevision($revision) !== ReviewStage::COORDINATION) {
                throw ValidationException::withMessages(['revision' => 'Esta revisión no está en la etapa de coordinación.']);
            }

            $resolved = ReviewObservation::query()
                ->whereHas('revision', fn ($query) => $query->where('silabo_id', $syllabus->id))
                ->where('estado', '!=', 'verificada')
                ->update(['estado' => 'verificada']);
            $review = CoordinationReview::query()->create([
                'revision_silabo_id' => $revision->id,
                'revisado_por' => $actor->id,
                'revisado_en' => now(),
            ]);
            $syllabus->update(['version_bloqueo' => $syllabus->version_bloqueo + 1]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'silabo.revisado_coordinacion',
                resourceType: 'revision_coordinacion',
                resourceId: $review->id,
                result: 'exito',
                metadata: ['revision_number' => $revision->numero_revision, 'resolved_observations' => $resolved],
            );
            $this->notifications->execute(
                syllabus: $syllabus,
                eventType: 'silabo.revisado_coordinacion',
                deduplicationKey: "silabo.revisado_coordinacion:{$review->id}",
                recipientIds: $this->recipients->deansFor($syllabus),
                revisionNumber: $revision->numero_revision,
            );

            return $review;
        });
    }
}
