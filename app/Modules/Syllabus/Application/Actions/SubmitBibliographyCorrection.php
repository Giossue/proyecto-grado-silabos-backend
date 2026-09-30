<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\BibliographyCorrection;
use App\Modules\Syllabus\Domain\Exceptions\DraftConflictException;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Reopening;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmitBibliographyCorrection
{
    public function __construct(
        private readonly CanonicalHasher $hasher,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    public function execute(
        Syllabus $syllabus,
        int $expectedLockVersion,
        string $idempotencyKey,
        User $actor,
        Request $request,
    ): SyllabusRevision {
        return DB::transaction(function () use ($actor, $expectedLockVersion, $idempotencyKey, $request, $syllabus): SyllabusRevision {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $existing = SyllabusRevision::query()
                ->where('silabo_id', $locked->id)
                ->where('clave_idempotencia', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                abort_if($existing->enviado_por !== $actor->id, 403);

                return $existing;
            }
            if ($locked->version_bloqueo !== $expectedLockVersion) {
                throw new DraftConflictException($locked->version_bloqueo);
            }
            $reopening = BibliographyCorrection::pendingReopening($locked);
            if ($reopening?->origen_reapertura !== Reopening::ORIGIN_LIBRARY) {
                throw ValidationException::withMessages(['syllabus' => 'Biblioteca no tiene una corrección pendiente en este sílabo.']);
            }
            $this->assertBibliographyFilled($locked);

            $baseApproval = $reopening->approval()->firstOrFail();
            $approvedRevision = $reopening->approvedRevision()->firstOrFail();
            $values = $locked->values()->get()->mapWithKeys(fn ($value): array => [$value->definicion_campo_id => $value->valor])->all();
            $snapshot = BibliographyCorrection::replaceValues($approvedRevision->fotografia, $values);
            $previous = SyllabusRevision::query()
                ->where('silabo_id', $locked->id)
                ->orderByDesc('numero_revision')
                ->lockForUpdate()
                ->firstOrFail();
            $revision = SyllabusRevision::query()->create([
                'silabo_id' => $locked->id,
                'revision_anterior_id' => $previous->id,
                'reapertura_id' => $reopening->id,
                'numero_revision' => $previous->numero_revision + 1,
                'clave_idempotencia' => $idempotencyKey,
                'version_bloqueo_origen' => $locked->version_bloqueo,
                'fotografia' => $snapshot,
                'huella_sha256' => $this->hasher->hash($snapshot),
                'enviado_por' => $actor->id,
                'enviado_en' => now(),
            ]);

            $approvedAt = now();
            $approvalKey = (string) Str::uuid();
            $approval = Approval::query()->create([
                'revision_silabo_id' => $revision->id,
                'clave_idempotencia' => $approvalKey,
                'huella_sha256' => $this->hasher->hash([
                    'revision_id' => $revision->id,
                    'revision_fingerprint' => $revision->huella_sha256,
                    'approved_by' => $baseApproval->aprobado_por,
                    'approved_at' => $approvedAt->toIso8601String(),
                    'idempotency_key' => $approvalKey,
                    'base_approval_id' => $baseApproval->id,
                ]),
                'aprobado_por' => $baseApproval->aprobado_por,
                'aprobado_en' => $approvedAt,
                'aprobacion_base_id' => $baseApproval->id,
            ]);
            $locked->update([
                'estado' => 'aprobado',
                'version_bloqueo' => $locked->version_bloqueo + 1,
                'guardado_en' => now(),
            ]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'silabo.bibliografia_corregida',
                resourceType: 'revision_silabo',
                resourceId: $revision->id,
                result: 'exito',
                metadata: [
                    'revision_number' => $revision->numero_revision,
                    'from_state' => 'correccion_solicitada',
                    'to_state' => 'aprobado',
                    'fingerprint' => $revision->huella_sha256,
                    'approval_id' => $approval->id,
                    'base_approval_id' => $baseApproval->id,
                ],
            );
            $this->notifications->execute(
                syllabus: $locked,
                eventType: 'silabo.bibliografia_corregida',
                deduplicationKey: "silabo.bibliografia_corregida:{$revision->id}",
                recipientIds: $this->recipients->coordinatorsFor($locked),
                revisionNumber: $revision->numero_revision,
            );

            return $revision;
        });
    }

    private function assertBibliographyFilled(Syllabus $syllabus): void
    {
        $values = $syllabus->values()->get()->keyBy('definicion_campo_id');
        $missing = FieldDefinition::query()
            ->with('template')
            ->where('editable_docente', true)
            ->where('heredado', false)
            ->get()
            ->filter(fn (FieldDefinition $field): bool => BibliographyCorrection::includes($field)
                && $field->block?->contentType() !== 'fixed'
                && ! ValidateDraft::filled($values->get($field->id)?->valor));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'validation' => 'Complete la bibliografía antes de reenviarla: falta «'.$missing->first()->displayName().'».',
            ]);
        }
    }
}
