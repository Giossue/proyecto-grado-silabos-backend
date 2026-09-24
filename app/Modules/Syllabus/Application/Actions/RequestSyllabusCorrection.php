<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CorrectionRequest;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\ReviewObservation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestSyllabusCorrection
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    public function execute(
        SyllabusRevision $revision,
        string $justification,
        User $actor,
        Request $request,
    ): CorrectionRequest {
        return DB::transaction(function () use ($actor, $justification, $request, $revision): CorrectionRequest {
            $syllabus = Syllabus::query()->lockForUpdate()->findOrFail($revision->silabo_id);
            $latestId = SyllabusRevision::query()
                ->where('silabo_id', $syllabus->id)
                ->orderByDesc('numero_revision')
                ->value('id');
            if ($syllabus->estado !== 'en_revision' || $latestId !== $revision->id) {
                throw ValidationException::withMessages([
                    'revision' => 'La revisión dejó de estar disponible para solicitar corrección.',
                ]);
            }
            if (CorrectionRequest::query()->where('revision_silabo_id', $revision->id)->exists()) {
                throw ValidationException::withMessages(['revision' => 'Esta revisión ya tiene una solicitud de corrección.']);
            }

            // La solicitud envía todas las observaciones abiertas de la revisión vigente.
            $observations = ReviewObservation::query()
                ->where('revision_silabo_id', $revision->id)
                ->where('estado', 'abierta')
                ->lockForUpdate()
                ->get();
            if ($observations->isEmpty()) {
                throw ValidationException::withMessages([
                    'revision' => 'Registra al menos una observación pendiente en esta revisión antes de solicitar corrección.',
                ]);
            }

            $correction = CorrectionRequest::query()->create([
                'revision_silabo_id' => $revision->id,
                'justificacion' => $justification,
                'solicitado_por' => $actor->id,
                'solicitado_en' => now(),
            ]);

            $syllabus->update([
                'estado' => 'correccion_solicitada',
                'version_bloqueo' => $syllabus->version_bloqueo + 1,
                'guardado_en' => now(),
            ]);
            $activeRole = $this->roles->resolve($request);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'silabo.correccion_solicitada',
                resourceType: 'solicitud_correccion',
                resourceId: $correction->id,
                result: 'exito',
                metadata: ['revision_number' => $revision->numero_revision, 'from_state' => 'en_revision', 'to_state' => 'correccion_solicitada', 'observation_count' => $observations->count()],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
            $this->notifications->execute(
                syllabus: $syllabus,
                eventType: 'silabo.correccion_solicitada',
                deduplicationKey: "silabo.correccion_solicitada:{$correction->id}",
                recipientIds: $this->recipients->teachersFor($syllabus),
                revisionNumber: $revision->numero_revision,
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $correction;
        });
    }
}
