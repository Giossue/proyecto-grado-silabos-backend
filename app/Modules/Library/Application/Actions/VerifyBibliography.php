<?php

namespace App\Modules\Library\Application\Actions;

use App\Models\User;
use App\Modules\Documents\Application\SignatureWorkflow;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerifyBibliography
{
    public function __construct(
        private readonly SignatureWorkflow $workflow,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    public function execute(SyllabusRevision $revision, User $actor, Request $request): LibrarySubmission
    {
        abort_unless($this->workflow->roleOf($actor, $revision, $request) === DocumentSignature::ROLE_LIBRARY, 403);

        return DB::transaction(function () use ($actor, $request, $revision): LibrarySubmission {
            $syllabus = Syllabus::query()->lockForUpdate()->findOrFail($revision->silabo_id);
            $submission = LibrarySubmission::query()->where('revision_silabo_id', $revision->id)->lockForUpdate()->firstOrFail();
            if ($submission->verificado_en !== null) {
                return $submission;
            }
            $artifact = $this->workflow->artifactFor($revision);
            if ($this->workflow->state($revision, $artifact, $this->workflow->signatures($artifact)) !== SignatureWorkflow::STATE_PENDING_LIBRARY_REVIEW) {
                throw ValidationException::withMessages(['revision' => 'Este sílabo ya no espera la verificación de biblioteca.']);
            }

            $submission->update(['verificado_por' => $actor->id, 'verificado_en' => now()]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'biblioteca.verificado',
                resourceType: 'envio_biblioteca',
                resourceId: $submission->id,
                result: 'exito',
                metadata: ['revision_number' => $revision->numero_revision],
            );
            $this->notifications->execute(
                syllabus: $syllabus,
                eventType: 'biblioteca.verificado',
                deduplicationKey: "biblioteca.verificado:{$submission->id}",
                recipientIds: $this->recipients->teachersFor($syllabus),
                revisionNumber: $revision->numero_revision,
            );

            return $submission;
        });
    }
}
