<?php

namespace App\Modules\Documents\Application;

use App\Models\User;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class SignatureWorkflow
{
    public const STATE_NO_DOCUMENT = 'sin_documento';

    public const STATE_PENDING_TEACHER = 'pendiente_docente';

    public const STATE_PENDING_COORDINATION = 'pendiente_coordinacion';

    public const STATE_PENDING_LIBRARY_SUBMISSION = 'pendiente_envio_biblioteca';

    public const STATE_PENDING_LIBRARY_REVIEW = 'pendiente_verificacion_biblioteca';

    public const STATE_PENDING_LIBRARY = 'pendiente_biblioteca';

    public const PENDING_STATES = [
        self::STATE_PENDING_TEACHER,
        self::STATE_PENDING_COORDINATION,
        self::STATE_PENDING_LIBRARY,
    ];

    public const STATE_SIGNED = 'firmado';

    public const STATE_VOIDED = 'anulado';

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    public function artifactFor(SyllabusRevision $revision): ?ExportArtifact
    {
        $signed = ExportArtifact::query()
            ->where('revision_silabo_id', $revision->id)
            ->whereIn('id', DocumentSignature::query()->select('artefacto_exportacion_id'))
            ->first();

        return $signed ?? ExportArtifact::query()
            ->where('revision_silabo_id', $revision->id)
            ->where('estado', 'completado')
            ->latest('solicitado_en')
            ->first();
    }

    /** @return Collection<int, DocumentSignature> */
    public function signatures(?ExportArtifact $artifact): Collection
    {
        if ($artifact === null) {
            return new Collection;
        }

        return DocumentSignature::query()
            ->where('artefacto_exportacion_id', $artifact->id)
            ->with(['signer:id,nombre', 'storedObject'])
            ->get()
            ->sortBy(fn (DocumentSignature $signature): int|false => array_search($signature->rol_firma, DocumentSignature::ORDER, true))
            ->values();
    }

    /** @param Collection<int, DocumentSignature> $signatures */
    public function nextRole(Collection $signatures): ?string
    {
        $roles = $signatures->pluck('rol_firma')->all();
        foreach (DocumentSignature::ORDER as $role) {
            if (! in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }

    public function submission(SyllabusRevision $revision): ?LibrarySubmission
    {
        return LibrarySubmission::query()
            ->where('revision_silabo_id', $revision->id)
            ->with(['sender:id,nombre', 'verifier:id,nombre'])
            ->first();
    }

    /** @param Collection<int, DocumentSignature> $signatures */
    public function currentPdf(ExportArtifact $artifact, Collection $signatures): ?StoredObject
    {
        $last = $signatures->last();

        return $last instanceof DocumentSignature ? $last->storedObject : $artifact->pdfObject()->first();
    }

    public function voided(SyllabusRevision $revision): bool
    {
        $approval = Approval::query()->where('revision_silabo_id', $revision->id)->first();

        return $approval === null || $approval->reopening()->exists();
    }

    /**
     * @param  Collection<int, DocumentSignature>  $signatures
     * @return string one of the STATE_* constants
     */
    public function state(SyllabusRevision $revision, ?ExportArtifact $artifact, Collection $signatures): string
    {
        $next = $this->nextRole($signatures);
        if ($next === null) {
            return self::STATE_SIGNED;
        }
        if ($this->voided($revision)) {
            return self::STATE_VOIDED;
        }
        $submission = $this->submission($revision);
        if ($submission === null) {
            return self::STATE_PENDING_LIBRARY_SUBMISSION;
        }
        if ($submission->verificado_en === null) {
            return self::STATE_PENDING_LIBRARY_REVIEW;
        }
        if ($artifact === null) {
            return self::STATE_NO_DOCUMENT;
        }

        return match ($next) {
            DocumentSignature::ROLE_TEACHER => self::STATE_PENDING_TEACHER,
            DocumentSignature::ROLE_COORDINATION => self::STATE_PENDING_COORDINATION,
            default => self::STATE_PENDING_LIBRARY,
        };
    }

    public function roleOf(User $user, SyllabusRevision $revision, Request $request): ?string
    {
        $active = $this->roles->resolve($request);
        if (! $user->activo || $active === null) {
            return null;
        }
        $syllabus = $revision->syllabus;
        if ($active->role->codigo_rol === RoleCode::Teacher->value) {
            return $user->can('view', $syllabus) ? DocumentSignature::ROLE_TEACHER : null;
        }
        if ($active->role->codigo_rol === RoleCode::Coordinator->value
            && $user->can('review', $syllabus)
            && in_array($user->id, $this->recipients->coordinatorsFor($syllabus), true)) {
            return DocumentSignature::ROLE_COORDINATION;
        }
        if ($active->role->codigo_rol === RoleCode::Librarian->value) {
            return $user->can('view', $syllabus) ? DocumentSignature::ROLE_LIBRARY : null;
        }

        return null;
    }
}
