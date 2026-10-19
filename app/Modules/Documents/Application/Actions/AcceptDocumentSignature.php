<?php

namespace App\Modules\Documents\Application\Actions;

use App\Models\User;
use App\Modules\Documents\Application\IncrementalPdfSignatureVerifier;
use App\Modules\Documents\Application\SignatureWorkflow;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AcceptDocumentSignature
{
    public function __construct(
        private readonly SignatureWorkflow $workflow,
        private readonly IncrementalPdfSignatureVerifier $verifier,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
    ) {}

    public function execute(
        SyllabusRevision $revision,
        string $signedPdf,
        string $baseFingerprint,
        User $actor,
        Request $request,
    ): DocumentSignature {
        $role = $this->workflow->roleOf($actor, $revision, $request);
        abort_if($role === null, 403);

        $path = null;
        try {
            return DB::transaction(function () use ($actor, $baseFingerprint, $request, $revision, $role, $signedPdf, &$path): DocumentSignature {
                $artifact = $this->workflow->artifactFor($revision);
                if ($artifact !== null) {
                    $artifact = ExportArtifact::query()->lockForUpdate()->findOrFail($artifact->id);
                }
                $signatures = $this->workflow->signatures($artifact);
                $state = $this->workflow->state($revision, $artifact, $signatures);
                if ($state === SignatureWorkflow::STATE_VOIDED) {
                    $this->fail('Esta revisión no tiene una aprobación vigente: no se firma.');
                }
                if ($artifact === null) {
                    $this->fail('Primero genere el PDF de la revisión aprobada.');
                }
                $expected = $this->workflow->nextRole($signatures);
                if ($expected === null) {
                    $this->fail('El documento ya tiene todas las firmas.');
                }
                if ($expected !== $role) {
                    $this->fail($this->outOfTurn($expected, $role));
                }

                $current = $this->workflow->currentPdf($artifact, $signatures);
                if ($current === null || ! Storage::disk($current->disco)->exists($current->ruta_interna)) {
                    $this->fail('No se encontró el PDF vigente de la revisión.');
                }
                if (! hash_equals($current->huella_sha256, strtolower($baseFingerprint))) {
                    $this->fail('El documento cambió mientras firmaba. Descárguelo de nuevo y vuelva a firmar.');
                }
                $previous = (string) Storage::disk($current->disco)->get($current->ruta_interna);
                $certificate = $this->verifier->verify($previous, $signedPdf);

                $revision->loadMissing('syllabus.convocation');
                $syllabus = $revision->syllabus;
                $path = "exports/{$revision->id}/{$artifact->id}/silabo-firmado-{$role}.pdf";
                $fingerprint = hash('sha256', $signedPdf);
                Storage::disk('private')->put($path, $signedPdf);
                $object = StoredObject::query()->create([
                    'disco' => 'private',
                    'ruta_interna' => $path,
                    'nombre_logico' => "silabo-revision-{$revision->numero_revision}-firmado.pdf",
                    'mime' => 'application/pdf',
                    'tamano_bytes' => strlen($signedPdf),
                    'huella_sha256' => $fingerprint,
                    'clasificacion' => 'syllabus_signed_export',
                    'propietario_usuario_id' => $actor->id,
                    'almacenado_en' => now(),
                ]);
                $signature = DocumentSignature::query()->create([
                    'artefacto_exportacion_id' => $artifact->id,
                    'rol_firma' => $role,
                    'usuario_id' => $actor->id,
                    'objeto_almacenado_id' => $object->id,
                    'certificado_titular' => $certificate->holder,
                    'certificado_serie' => $certificate->serial,
                    'certificado_emisor' => $certificate->issuer,
                    'certificado_vence_en' => $certificate->validTo,
                    'firmado_en' => now(),
                ]);

                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $this->roles->resolve($request)?->id,
                    action: 'documento.firmado',
                    resourceType: 'firma_documento',
                    resourceId: $signature->id,
                    result: 'exito',
                    metadata: [
                        'role' => $role,
                        'revision_number' => $revision->numero_revision,
                        'artifact_id' => $artifact->id,
                        'object_id' => $object->id,
                        'certificate_holder' => $certificate->holder,
                        'certificate_serial' => $certificate->serial,
                        'sha256' => $fingerprint,
                    ],
                );
                [$eventType, $recipientIds] = match ($role) {
                    DocumentSignature::ROLE_TEACHER => ['silabo.firmado_docente', $this->recipients->coordinatorsFor($syllabus)],
                    DocumentSignature::ROLE_COORDINATION => ['silabo.firmado_coordinacion', $this->recipients->librarians()],
                    default => [null, []],
                };
                if ($eventType !== null) {
                    $this->notifications->execute(
                        syllabus: $syllabus,
                        eventType: $eventType,
                        deduplicationKey: "documento.firmado:{$signature->id}",
                        recipientIds: $recipientIds,
                        revisionNumber: $revision->numero_revision,
                    );
                }

                return $signature;
            });
        } catch (\Throwable $exception) {
            if (is_string($path) && ! DocumentSignature::query()->whereHas('storedObject', fn ($query) => $query->where('ruta_interna', $path))->exists()) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }
    }

    private function outOfTurn(string $expected, string $role): string
    {
        $expectedIndex = (int) array_search($expected, DocumentSignature::ORDER, true);
        $roleIndex = (int) array_search($role, DocumentSignature::ORDER, true);
        $names = [
            DocumentSignature::ROLE_TEACHER => 'el docente',
            DocumentSignature::ROLE_COORDINATION => 'la coordinación',
            DocumentSignature::ROLE_LIBRARY => 'biblioteca',
        ];

        return $roleIndex > $expectedIndex
            ? "Primero debe firmar {$names[$expected]}."
            : "Su firma ya está registrada; ahora firma {$names[$expected]}.";
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['signed_pdf' => $message]);
    }
}
