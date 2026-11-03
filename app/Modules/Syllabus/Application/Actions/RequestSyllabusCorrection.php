<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Domain\Policies\SyllabusPolicy;
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
    ): SyllabusRevision {
        $activeRole = $this->roles->resolve($request);
        $stage = $activeRole === null ? null : ReviewStage::forRole($activeRole->role->codigo_rol);
        abort_if($stage === null, 403);

        return DB::transaction(function () use ($actor, $activeRole, $justification, $revision, $stage): SyllabusRevision {
            // Proceso y convocatoria primero (compartido), luego el sílabo: el orden de pausas y cierres.
            $block = SyllabusPolicy::lockedDecisionBlock($revision->syllabus);
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
            if ($block !== null) {
                throw ValidationException::withMessages(['revision' => $block]);
            }
            $locked = SyllabusRevision::query()->lockForUpdate()->findOrFail($revision->id);
            if ($locked->hasCorrectionRequest()) {
                throw ValidationException::withMessages(['revision' => 'Esta revisión ya tiene una solicitud de corrección.']);
            }
            if (ReviewStage::ofRevision($revision) !== $stage) {
                throw ValidationException::withMessages(['revision' => 'La revisión no está en su etapa; otro revisor la tiene ahora.']);
            }

            $locked->update([
                'correccion_justificacion' => $justification,
                'correccion_etapa' => $stage,
                'correccion_solicitada_por' => $actor->id,
                'correccion_solicitada_en' => now(),
            ]);

            $syllabus->update([
                'estado' => 'correccion_solicitada',
                'version_bloqueo' => $syllabus->version_bloqueo + 1,
                'guardado_en' => now(),
            ]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'silabo.correccion_solicitada',
                resourceType: 'revision_silabo',
                resourceId: $revision->id,
                result: 'exito',
                metadata: ['revision_number' => $revision->numero_revision, 'from_state' => 'en_revision', 'to_state' => 'correccion_solicitada', 'stage' => $stage],
            );
            $this->notifications->execute(
                syllabus: $syllabus,
                eventType: 'silabo.correccion_solicitada',
                deduplicationKey: "silabo.correccion_solicitada:{$revision->id}",
                recipientIds: $this->recipients->teachersFor($syllabus),
                revisionNumber: $revision->numero_revision,
            );
            if ($stage === ReviewStage::LIBRARY) {
                $this->notifications->execute(
                    syllabus: $syllabus,
                    eventType: 'biblioteca.correccion_solicitada',
                    deduplicationKey: "biblioteca.correccion_solicitada:{$revision->id}",
                    recipientIds: $this->recipients->coordinatorsFor($syllabus),
                    revisionNumber: $revision->numero_revision,
                );
            }

            return $locked;
        });
    }
}
