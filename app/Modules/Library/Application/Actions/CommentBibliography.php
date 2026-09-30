<?php

namespace App\Modules\Library\Application\Actions;

use App\Models\User;
use App\Modules\Documents\Application\SignatureWorkflow;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Reopening;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommentBibliography
{
    public function __construct(
        private readonly SignatureWorkflow $workflow,
        private readonly SyllabusSnapshot $snapshots,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    public function execute(
        SyllabusRevision $revision,
        string $comment,
        string $idempotencyKey,
        User $actor,
        Request $request,
    ): Reopening {
        abort_unless($this->workflow->roleOf($actor, $revision, $request) === DocumentSignature::ROLE_LIBRARY, 403);

        return DB::transaction(function () use ($actor, $comment, $idempotencyKey, $request, $revision): Reopening {
            $syllabus = Syllabus::query()->lockForUpdate()->findOrFail($revision->silabo_id);
            $existing = $syllabus->reopenings()->where('reaperturas.clave_idempotencia', $idempotencyKey)->first();
            if ($existing !== null) {
                abort_if($existing->reabierto_por !== $actor->id, 403);

                return $existing;
            }
            $artifact = $this->workflow->artifactFor($revision);
            $state = $this->workflow->state($revision, $artifact, $this->workflow->signatures($artifact));
            if ($syllabus->estado !== 'aprobado' || $state !== SignatureWorkflow::STATE_PENDING_LIBRARY_REVIEW) {
                throw ValidationException::withMessages(['comment' => 'Este sílabo ya no espera la verificación de biblioteca.']);
            }

            $approval = Approval::query()->where('revision_silabo_id', $revision->id)->firstOrFail();
            $reopening = Reopening::query()->create([
                'aprobacion_id' => $approval->id,
                'revision_aprobada_id' => $revision->id,
                'clave_idempotencia' => $idempotencyKey,
                'causa' => $comment,
                'origen_reapertura' => Reopening::ORIGIN_LIBRARY,
                'reabierto_por' => $actor->id,
                'reabierto_en' => now(),
            ]);
            $this->snapshots->restore($syllabus, $revision->fotografia);
            $syllabus->update([
                'estado' => 'correccion_solicitada',
                'version_bloqueo' => $syllabus->version_bloqueo + 1,
                'guardado_en' => now(),
            ]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'biblioteca.comentado',
                resourceType: 'reapertura',
                resourceId: $reopening->id,
                result: 'exito',
                metadata: [
                    'revision_number' => $revision->numero_revision,
                    'from_state' => 'aprobado',
                    'to_state' => 'correccion_solicitada',
                    'approval_id' => $approval->id,
                ],
            );
            $this->notifications->execute(
                syllabus: $syllabus,
                eventType: 'biblioteca.comentado',
                deduplicationKey: "biblioteca.comentado:{$reopening->id}",
                recipientIds: $this->recipients->teachersFor($syllabus),
                revisionNumber: $revision->numero_revision,
            );

            return $reopening;
        });
    }
}
