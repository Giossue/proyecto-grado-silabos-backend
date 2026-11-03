<?php

namespace App\Modules\Library\Application\Actions;

use App\Models\User;
use App\Modules\Documents\Application\Actions\RequestSyllabusExport;
use App\Modules\Documents\Application\Actions\SignSyllabusDecision;
use App\Modules\Documents\Domain\Data\DecisionSignature;
use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VerifyBibliography
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly CanonicalHasher $hasher,
        private readonly RequestSyllabusExport $exports,
        private readonly SignSyllabusDecision $signing,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    public function execute(SyllabusRevision $revision, ?DecisionSignature $signature, User $actor, Request $request): LibrarySubmission
    {
        abort_unless($actor->can('decide', $revision->syllabus), 403);

        return DB::transaction(function () use ($actor, $request, $revision, $signature): LibrarySubmission {
            $syllabus = Syllabus::query()->lockForUpdate()->findOrFail($revision->silabo_id);
            $submission = LibrarySubmission::query()->where('revision_silabo_id', $revision->id)->lockForUpdate()->firstOrFail();
            if (Approval::query()->where('revision_silabo_id', $revision->id)->exists()) {
                return $submission;
            }
            $latestId = SyllabusRevision::query()->where('silabo_id', $syllabus->id)->orderByDesc('numero_revision')->value('id');
            if ($syllabus->estado !== 'en_revision' || $latestId !== $revision->id || ReviewStage::ofRevision($revision) !== ReviewStage::LIBRARY) {
                throw ValidationException::withMessages(['revision' => 'Este sílabo ya no espera la revisión de biblioteca.']);
            }

            $this->signing->execute($revision, SyllabusSignature::ROLE_LIBRARY, $signature, $actor, $request);
            $approvedAt = now();
            $idempotencyKey = (string) Str::uuid();
            $fingerprint = $this->hasher->hash([
                'revision_id' => $revision->id,
                'revision_fingerprint' => $revision->huella_sha256,
                'approved_by' => $actor->id,
                'approved_at' => $approvedAt->toIso8601String(),
                'idempotency_key' => $idempotencyKey,
            ]);
            $approval = Approval::query()->create([
                'revision_silabo_id' => $revision->id,
                'clave_idempotencia' => $idempotencyKey,
                'huella_sha256' => $fingerprint,
                'aprobado_por' => $actor->id,
                'aprobado_en' => $approvedAt,
            ]);
            $syllabus->update(['estado' => 'aprobado', 'version_bloqueo' => $syllabus->version_bloqueo + 1]);
            $roleAssignmentId = $this->roles->resolve($request)?->id;
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $roleAssignmentId,
                action: 'biblioteca.verificado',
                resourceType: 'envio_biblioteca',
                resourceId: $submission->id,
                result: 'exito',
                metadata: ['revision_number' => $revision->numero_revision],
            );
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $roleAssignmentId,
                action: 'silabo.aprobado',
                resourceType: 'aprobacion',
                resourceId: $approval->id,
                result: 'exito',
                metadata: [
                    'revision_number' => $revision->numero_revision,
                    'from_state' => 'en_revision',
                    'to_state' => 'aprobado',
                    'fingerprint' => $fingerprint,
                    'self_approved' => $revision->enviado_por === $actor->id,
                ],
            );
            $this->notifications->execute(
                syllabus: $syllabus,
                eventType: 'biblioteca.aprobado',
                deduplicationKey: "biblioteca.aprobado:{$revision->id}",
                recipientIds: $this->recipients->coordinatorsFor($syllabus),
                revisionNumber: $revision->numero_revision,
            );
            $this->exports->execute($revision, $approval->id, $actor, $request);

            return $submission;
        });
    }
}
