<?php

namespace App\Modules\Documents\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Application\Actions\AcceptDocumentSignature;
use App\Modules\Documents\Application\SignatureWorkflow;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Documents\Infrastructure\Rendering\SignatureStampLocator;
use App\Modules\Documents\Presentation\Http\Requests\SignRevisionDocumentRequest;
use App\Modules\Documents\Presentation\Http\Requests\ViewRevisionDocumentsRequest;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignatureController extends Controller
{
    public function show(
        SyllabusRevision $revision,
        ViewRevisionDocumentsRequest $request,
        SignatureWorkflow $workflow,
        SignatureStampLocator $stamps,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $artifact = $workflow->artifactFor($revision);
        $signatures = $workflow->signatures($artifact);
        $state = $workflow->state($revision, $artifact, $signatures);
        $next = in_array($state, SignatureWorkflow::PENDING_STATES, true)
            ? $workflow->nextRole($signatures)
            : null;
        $role = $workflow->roleOf($actor, $revision, $request);
        $current = $artifact === null ? null : $workflow->currentPdf($artifact, $signatures);
        $canSign = $next !== null && $next === $role && $current !== null;
        $submission = $workflow->submission($revision);
        $stamp = null;
        if ($canSign && Storage::disk($current->disco)->exists($current->ruta_interna)) {
            $stamp = $stamps->locate((string) Storage::disk($current->disco)->get($current->ruta_interna), $next);
        }

        return response()->json([
            'state' => $state,
            'next_role' => $next,
            'my_role' => $role,
            'can_sign' => $canSign,
            'can_send_to_library' => $state === SignatureWorkflow::STATE_PENDING_LIBRARY_SUBMISSION
                && $role === DocumentSignature::ROLE_TEACHER,
            'library' => $submission === null ? null : [
                'sent_at' => $submission->enviado_en->toIso8601String(),
                'sent_by' => $submission->sender->nombre,
                'verified_at' => $submission->verificado_en?->toIso8601String(),
                'verified_by' => $submission->verifier?->nombre,
            ],
            'artifact_id' => $artifact?->id,
            'document' => $current === null ? null : [
                'sha256' => $current->huella_sha256,
                'size' => $current->tamano_bytes,
            ],
            'stamp' => $stamp,
            'signatures' => $signatures->map(fn (DocumentSignature $signature): array => [
                'role' => $signature->rol_firma,
                'signer' => $signature->signer->nombre,
                'certificate_holder' => $signature->certificado_titular,
                'certificate_issuer' => $signature->certificado_emisor,
                'signed_at' => $signature->firmado_en->toIso8601String(),
            ])->values(),
        ]);
    }

    public function pdf(
        SyllabusRevision $revision,
        ViewRevisionDocumentsRequest $request,
        SignatureWorkflow $workflow,
        ActiveRole $roles,
        RecordAuditEvent $audit,
    ): StreamedResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $artifact = $workflow->artifactFor($revision);
        abort_if($artifact === null, 404);
        $signatures = $workflow->signatures($artifact);
        $object = $workflow->currentPdf($artifact, $signatures);
        abort_unless($object !== null && Storage::disk($object->disco)->exists($object->ruta_interna), 404);
        $audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $roles->resolve($request)?->id,
            action: 'documento.descargado',
            resourceType: 'artefacto_exportacion',
            resourceId: $artifact->id,
            result: 'exito',
            metadata: [
                'format' => 'pdf',
                'signatures' => $signatures->count(),
                'revision_number' => $revision->numero_revision,
                'object_id' => $object->id,
            ],
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );
        $code = Str::slug($revision->syllabus->academicSubjectCode()) ?: 'asignatura';
        $suffix = $signatures->isEmpty() ? '' : '-firmado';

        return Storage::disk($object->disco)->download(
            $object->ruta_interna,
            "silabo-{$code}-revision-{$revision->numero_revision}{$suffix}.pdf",
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'X-Document-Sha256' => $object->huella_sha256,
            ],
        );
    }

    public function store(
        SyllabusRevision $revision,
        SignRevisionDocumentRequest $request,
        AcceptDocumentSignature $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $file = $request->file('signed_pdf');
        abort_if($file === null || is_array($file), 422);
        $signature = $action->execute(
            $revision,
            (string) file_get_contents($file->getRealPath()),
            $request->string('base_sha256')->toString(),
            $actor,
            $request,
        );

        return response()->json([
            'message' => match ($signature->rol_firma) {
                DocumentSignature::ROLE_TEACHER => 'Firma registrada. Coordinación ya puede firmar el sílabo.',
                DocumentSignature::ROLE_COORDINATION => 'Firma registrada. Biblioteca ya puede firmar el sílabo.',
                default => 'Firma registrada. El sílabo quedó firmado por docente, coordinación y biblioteca.',
            },
            'signature_id' => $signature->id,
        ], 201);
    }
}
