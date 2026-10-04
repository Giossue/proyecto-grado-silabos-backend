<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Documents\Application\Actions\RequestSyllabusExport;
use App\Modules\Identity\Application\ActiveRole;
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
use Illuminate\Validation\ValidationException;

class ApproveSyllabus
{
    public function __construct(
        private readonly CanonicalHasher $hasher,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly RequestSyllabusExport $exports,
    ) {}

    public function execute(
        SyllabusRevision $revision,
        string $idempotencyKey,
        User $actor,
        Request $request,
    ): Approval {
        $existing = $this->existingApproval($revision->syllabus, $idempotencyKey, $actor);
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($actor, $idempotencyKey, $request, $revision): Approval {
            $syllabus = Syllabus::query()->lockForUpdate()->findOrFail($revision->silabo_id);
            $existing = $this->existingApproval($syllabus, $idempotencyKey, $actor);
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
            if (ReviewStage::ofRevision($revision) !== ReviewStage::DEANERY) {
                throw ValidationException::withMessages(['revision' => 'La revisión aún no llega al decanato: faltan biblioteca o coordinación.']);
            }

            $approvedAt = now();
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
            $activeRole = $this->roles->resolve($request);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
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
                eventType: 'silabo.aprobado',
                deduplicationKey: "silabo.aprobado:{$approval->id}",
                recipientIds: array_values(array_unique([...$this->recipients->teachersFor($syllabus), ...$this->recipients->coordinatorsFor($syllabus)])),
                revisionNumber: $revision->numero_revision,
            );
            // I-173: el PDF se genera al terminar la cadena de revisión; después firman los cuatro.
            $this->exports->execute($revision, $approval->id, $actor, $request);

            return $approval;
        });
    }

    private function existingApproval(Syllabus $syllabus, string $key, User $actor): ?Approval
    {
        $approval = $syllabus->approvals()
            ->where('aprobaciones.clave_solicitud', $key)
            ->first();
        if ($approval !== null && $approval->aprobado_por !== $actor->id) {
            abort(403);
        }

        return $approval;
    }
}
