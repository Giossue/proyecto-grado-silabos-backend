<?php

namespace App\Modules\Documents\Application;

use App\Models\User;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * I-139. Reglas del proceso de firma de una revisión aprobada:
 *
 * - se firma el PDF de un artefacto de exportación completado: el que ya tenga firmas o,
 *   si ninguno, el más reciente;
 * - orden fijo: docente vigente del sílabo y después coordinación vigente de la carrera;
 * - el PDF vigente es el último firmado o, sin firmas, el exportado;
 * - reabrir la aprobación anula el proceso de esa revisión.
 */
class SignatureWorkflow
{
    public const STATE_NO_DOCUMENT = 'sin_documento';

    public const STATE_PENDING_TEACHER = 'pendiente_docente';

    public const STATE_PENDING_COORDINATION = 'pendiente_coordinacion';

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
            // El orden es fijo: 'docente' antes que 'coordinacion'.
            ->orderByDesc('rol_firma')
            ->get();
    }

    /** @param Collection<int, DocumentSignature> $signatures */
    public function nextRole(Collection $signatures): ?string
    {
        $roles = $signatures->pluck('rol_firma')->all();
        if (! in_array(DocumentSignature::ROLE_TEACHER, $roles, true)) {
            return DocumentSignature::ROLE_TEACHER;
        }

        return in_array(DocumentSignature::ROLE_COORDINATION, $roles, true) ? null : DocumentSignature::ROLE_COORDINATION;
    }

    /** @param Collection<int, DocumentSignature> $signatures */
    public function currentPdf(ExportArtifact $artifact, Collection $signatures): ?StoredObject
    {
        $last = $signatures->last();

        return $last instanceof DocumentSignature ? $last->storedObject : $artifact->pdfObject()->first();
    }

    /** Reabrir conserva la aprobación, pero su proceso de firma queda anulado. */
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
        if ($artifact === null) {
            return self::STATE_NO_DOCUMENT;
        }

        return $next === DocumentSignature::ROLE_TEACHER ? self::STATE_PENDING_TEACHER : self::STATE_PENDING_COORDINATION;
    }

    /**
     * Rol con el que la persona puede firmar esta revisión según su rol activo: el
     * docente vigente del sílabo o la coordinación vigente de la carrera.
     */
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

        return null;
    }
}
