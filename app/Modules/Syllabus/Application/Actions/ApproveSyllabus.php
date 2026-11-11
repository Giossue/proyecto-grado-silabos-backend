<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Documents\Application\Actions\RequestSyllabusExport;
use App\Modules\Documents\Application\Actions\SignSyllabusDecision;
use App\Modules\Documents\Domain\Data\DecisionSignature;
use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Domain\Policies\SyllabusPolicy;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CoordinationReview;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApproveSyllabus
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly CanonicalHasher $hasher,
        private readonly RequestSyllabusExport $exports,
        private readonly SignSyllabusDecision $signing,
    ) {}

    public function execute(SyllabusRevision $revision, ?DecisionSignature $signature, User $actor, Request $request): Approval
    {
        return DB::transaction(function () use ($actor, $request, $revision, $signature): Approval {
            // Proceso y convocatoria primero (compartido), luego el sílabo: el orden de pausas y cierres.
            $block = SyllabusPolicy::lockedDecisionBlock($revision->syllabus);
            $syllabus = Syllabus::query()->lockForUpdate()->findOrFail($revision->silabo_id);
            $existing = Approval::query()->where('revision_silabo_id', $revision->id)->first();
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
            if ($block !== null) {
                throw ValidationException::withMessages(['revision' => $block]);
            }

            $this->signing->execute($revision, SyllabusSignature::ROLE_COORDINATION, $signature, $actor, $request);
            $approvedAt = now();
            $review = CoordinationReview::query()->where('revision_silabo_id', $revision->id)->first()
                ?? CoordinationReview::query()->create([
                    'revision_silabo_id' => $revision->id,
                    'revisado_por' => $actor->id,
                    'revisado_en' => $approvedAt,
                ]);
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
            if ($review->wasRecentlyCreated) {
                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $roleAssignmentId,
                    action: 'silabo.revisado_coordinacion',
                    resourceType: 'revision_coordinacion',
                    resourceId: $review->id,
                    result: 'exito',
                    metadata: ['revision_number' => $revision->numero_revision],
                );
            }
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
            $this->exports->execute($revision, $approval->id, $actor, $request);

            return $approval;
        });
    }
}
