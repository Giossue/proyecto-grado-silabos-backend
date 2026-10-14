<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CoordinationReview;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveSyllabus
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
            $latest = SyllabusRevision::query()
                ->where('silabo_id', $syllabus->id)
                ->orderByDesc('numero_revision')
                ->firstOrFail();
            if ($syllabus->estado !== 'en_revision' || $latest->id !== $revision->id) {
                throw ValidationException::withMessages(['revision' => 'Solo puede aprobarse la revisión vigente.']);
            }
            if (ReviewStage::ofRevision($revision) !== ReviewStage::COORDINATION) {
                throw ValidationException::withMessages(['revision' => 'La revisión ya no espera la decisión de coordinación.']);
            }

            $review = CoordinationReview::query()->create([
                'revision_silabo_id' => $revision->id,
                'revisado_por' => $actor->id,
                'revisado_en' => now(),
            ]);
            LibrarySubmission::query()->create(['revision_silabo_id' => $revision->id]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'silabo.revisado_coordinacion',
                resourceType: 'revision_coordinacion',
                resourceId: $review->id,
                result: 'exito',
                metadata: ['revision_number' => $revision->numero_revision],
            );
            $this->notifications->execute(
                syllabus: $syllabus,
                eventType: 'coordinacion.aprobado',
                deduplicationKey: "coordinacion.aprobado:{$review->id}",
                recipientIds: $this->recipients->librarians(),
                revisionNumber: $revision->numero_revision,
            );

            return $review;
        });
    }
}
