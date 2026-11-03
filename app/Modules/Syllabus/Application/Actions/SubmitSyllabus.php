<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Documents\Application\Actions\SignSyllabusDecision;
use App\Modules\Documents\Domain\Data\DecisionSignature;
use App\Modules\Documents\Domain\SigningStatement;
use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Domain\Exceptions\DraftConflictException;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitSyllabus
{
    public function __construct(
        private readonly ValidateDraft $validateDraft,
        private readonly ConvocationSchedule $schedule,
        private readonly SyllabusSnapshot $snapshots,
        private readonly CanonicalHasher $hasher,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly SignSyllabusDecision $signing,
    ) {}

    /** @return array{statement: string, required: bool} */
    public function statement(Syllabus $syllabus, int $expectedLockVersion): array
    {
        $this->assertSubmittable($syllabus, $expectedLockVersion);

        return [
            'statement' => SigningStatement::for(
                $syllabus->id,
                SyllabusSignature::ROLE_TEACHER,
                $this->hasher->hash($this->snapshots->build($syllabus)),
            ),
            'required' => $this->signing->required($syllabus->id, SyllabusSignature::ROLE_TEACHER),
        ];
    }

    public function execute(
        Syllabus $syllabus,
        int $expectedLockVersion,
        string $idempotencyKey,
        ?DecisionSignature $signature,
        User $actor,
        Request $request,
    ): SyllabusRevision {
        $existing = $this->existingRevision($syllabus, $idempotencyKey, $actor);
        if ($existing !== null) {
            return $existing;
        }
        $this->assertSubmittable($syllabus, $expectedLockVersion);

        $validation = $this->validateDraft->execute($syllabus, $actor, $request);
        if ($validation->blockingErrors() > 0) {
            throw ValidationException::withMessages([
                'validation' => 'Corrija los errores determinísticos antes de enviar.',
            ]);
        }

        return DB::transaction(function () use ($actor, $expectedLockVersion, $idempotencyKey, $request, $signature, $syllabus): SyllabusRevision {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $existing = $this->existingRevision($locked, $idempotencyKey, $actor);
            if ($existing !== null) {
                return $existing;
            }
            if ($locked->version_bloqueo !== $expectedLockVersion) {
                throw new DraftConflictException($locked->version_bloqueo);
            }
            if (! in_array($locked->estado, ['borrador', 'correccion_solicitada'], true)) {
                throw ValidationException::withMessages(['syllabus' => 'La transición de envío ya no está permitida.']);
            }

            $previous = SyllabusRevision::query()
                ->where('silabo_id', $locked->id)
                ->orderByDesc('numero_revision')
                ->lockForUpdate()
                ->first();
            $snapshot = $this->snapshots->build($locked);
            $revision = SyllabusRevision::query()->create([
                'silabo_id' => $locked->id,
                'revision_anterior_id' => $previous?->id,
                'numero_revision' => $previous === null ? 1 : $previous->numero_revision + 1,
                'clave_idempotencia' => $idempotencyKey,
                'version_bloqueo_origen' => $locked->version_bloqueo,
                'fotografia' => $snapshot,
                'huella_sha256' => $this->hasher->hash($snapshot),
                'enviado_por' => $actor->id,
                'enviado_en' => now(),
            ]);

            $this->signing->execute($revision, SyllabusSignature::ROLE_TEACHER, $signature, $actor, $request);

            $toLibrary = $previous?->correccion_etapa === ReviewStage::LIBRARY;
            if ($toLibrary) {
                LibrarySubmission::query()->create(['revision_silabo_id' => $revision->id]);
            }

            $from = $locked->estado;
            $action = $from === 'borrador' ? 'enviar' : 'reenviar';
            $locked->update([
                'estado' => 'en_revision',
                'version_bloqueo' => $locked->version_bloqueo + 1,
                'guardado_en' => now(),
            ]);
            $activeRole = $this->roles->resolve($request);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: match ($action) {
                    'enviar' => 'silabo.enviado',
                    'reenviar' => 'silabo.reenviado',
                },
                resourceType: 'revision_silabo',
                resourceId: $revision->id,
                result: 'exito',
                metadata: ['revision_number' => $revision->numero_revision, 'from_state' => $from, 'to_state' => 'en_revision', 'fingerprint' => $revision->huella_sha256],
            );
            // Coordinación no recibe aviso por cada envío: lo ve en Revisión y en el resumen diario.
            if ($toLibrary) {
                $this->notifications->execute(
                    syllabus: $locked,
                    eventType: 'silabo.reenviado_biblioteca',
                    deduplicationKey: "silabo.reenviado_biblioteca:{$revision->id}",
                    recipientIds: $this->recipients->librarians(),
                    revisionNumber: $revision->numero_revision,
                );
            }

            return $revision;
        });
    }

    private function assertSubmittable(Syllabus $syllabus, int $expectedLockVersion): void
    {
        if (! in_array($syllabus->estado, ['borrador', 'correccion_solicitada'], true)) {
            throw ValidationException::withMessages(['syllabus' => 'El expediente no está listo para envío.']);
        }
        $this->schedule->assertOpenForSubmission($syllabus->convocation()->firstOrFail());

        if ($syllabus->version_bloqueo !== $expectedLockVersion) {
            throw new DraftConflictException($syllabus->version_bloqueo);
        }
    }

    private function existingRevision(Syllabus $syllabus, string $key, User $actor): ?SyllabusRevision
    {
        $revision = SyllabusRevision::query()
            ->where('silabo_id', $syllabus->id)
            ->where('clave_idempotencia', $key)
            ->first();
        if ($revision !== null && $revision->enviado_por !== $actor->id) {
            abort(403);
        }

        return $revision;
    }
}
