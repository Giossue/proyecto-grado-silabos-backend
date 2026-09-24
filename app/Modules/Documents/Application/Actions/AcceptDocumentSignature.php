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

/**
 * I-139. Registra el PDF que la persona firmó en su navegador. El servidor nunca ve la
 * llave: solo comprueba que el archivo sea el PDF vigente más una firma válida.
 */
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
                    // Serializa las firmas del mismo artefacto.
                    $artifact = ExportArtifact::query()->lockForUpdate()->findOrFail($artifact->id);
                }
                $signatures = $this->workflow->signatures($artifact);
                $state = $this->workflow->state($revision, $artifact, $signatures);
                if ($state === SignatureWorkflow::STATE_VOIDED) {
                    $this->fail('El sílabo fue reabierto: esta revisión ya no se firma. Firme la nueva revisión cuando se apruebe.');
                }
                if ($artifact === null) {
                    $this->fail('Primero genere el PDF de la revisión aprobada.');
                }
                $expected = $this->workflow->nextRole($signatures);
                if ($expected === null) {
                    $this->fail('El documento ya tiene todas las firmas.');
                }
                if ($expected !== $role) {
                    $this->fail($expected === DocumentSignature::ROLE_TEACHER
                        ? 'Primero debe firmar el docente.'
                        : 'Su firma ya está registrada; ahora firma la coordinación.');
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
                    'estado' => 'activo',
                    'propietario_usuario_id' => $actor->id,
                    'carrera_id' => $syllabus->convocation->carrera_id,
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
                    'huella_sha256' => $fingerprint,
                    'firmado_en' => now(),
                ]);

                $correlationId = $request->attributes->getString('correlation_id') ?: null;
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
                    correlationId: $correlationId,
                );
                $finished = $role === DocumentSignature::ROLE_COORDINATION;
                $this->notifications->execute(
                    syllabus: $syllabus,
                    eventType: $finished ? 'silabo.firmado' : 'silabo.firmado_docente',
                    deduplicationKey: "documento.firmado:{$signature->id}",
                    recipientIds: $finished
                        ? $this->recipients->teachersFor($syllabus)
                        : $this->recipients->coordinatorsFor($syllabus),
                    revisionNumber: $revision->numero_revision,
                    correlationId: $correlationId,
                );

                return $signature;
            });
        } catch (\Throwable $exception) {
            // Un PDF escrito para una transacción que no llegó a confirmarse no debe quedar suelto.
            if (is_string($path) && ! DocumentSignature::query()->whereHas('storedObject', fn ($query) => $query->where('ruta_interna', $path))->exists()) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['signed_pdf' => $message]);
    }
}
