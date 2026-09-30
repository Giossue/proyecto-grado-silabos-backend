<?php

namespace App\Modules\Library\Application\Actions;

use App\Models\User;
use App\Modules\Documents\Application\SignatureWorkflow;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendToLibrary
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
        abort_unless($this->workflow->roleOf($actor, $revision, $request) === DocumentSignature::ROLE_TEACHER, 403);

        return DB::transaction(function () use ($actor, $request, $revision): LibrarySubmission {
            $artifact = $this->workflow->artifactFor($revision);
            if ($artifact === null) {
                throw ValidationException::withMessages(['revision' => 'Primero genere y firme el PDF de la revisión aprobada.']);
            }
            $artifact = ExportArtifact::query()->lockForUpdate()->findOrFail($artifact->id);
            $existing = $this->workflow->submission($artifact);
            if ($existing !== null) {
                return $existing;
            }
            $state = $this->workflow->state($revision, $artifact, $this->workflow->signatures($artifact));
            if ($state !== SignatureWorkflow::STATE_PENDING_LIBRARY_SUBMISSION) {
                throw ValidationException::withMessages(['revision' => 'Envíe el sílabo a biblioteca cuando el docente y la coordinación lo hayan firmado.']);
            }

            $submission = LibrarySubmission::query()->create([
                'artefacto_exportacion_id' => $artifact->id,
                'enviado_por' => $actor->id,
                'enviado_en' => now(),
            ]);
            $revision->loadMissing('syllabus');
            $correlationId = $request->attributes->getString('correlation_id') ?: null;
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'biblioteca.enviado',
                resourceType: 'envio_biblioteca',
                resourceId: $submission->id,
                result: 'exito',
                metadata: ['revision_number' => $revision->numero_revision, 'artifact_id' => $artifact->id],
                correlationId: $correlationId,
            );
            $this->notifications->execute(
                syllabus: $revision->syllabus,
                eventType: 'biblioteca.enviado',
                deduplicationKey: "biblioteca.enviado:{$submission->id}",
                recipientIds: $this->recipients->librarians(),
                revisionNumber: $revision->numero_revision,
                correlationId: $correlationId,
            );

            return $submission;
        });
    }
}
