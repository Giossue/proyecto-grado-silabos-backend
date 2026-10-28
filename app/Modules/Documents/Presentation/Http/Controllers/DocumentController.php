<?php

namespace App\Modules\Documents\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Application\Actions\RequestSyllabusExport;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Presentation\Http\Requests\DownloadExportRequest;
use App\Modules\Documents\Presentation\Http\Requests\RequestSyllabusExportRequest;
use App\Modules\Documents\Presentation\Http\Requests\ViewRevisionDocumentsRequest;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function show(SyllabusRevision $revision, ViewRevisionDocumentsRequest $request): JsonResponse
    {
        $revision->load([
            'approval.approver:id,nombre',
            'syllabus.parallel.subject:id,nombre,codigo_asignatura',
            'syllabus.convocation.process.academicPeriod:id,codigo',
        ]);
        abort_unless($revision->approval !== null, 404);
        $artifacts = ExportArtifact::query()
            ->where('revision_silabo_id', $revision->id)
            ->with('pdfObject')
            ->latest('solicitado_en')
            ->get();

        $payload = [
            'syllabus' => [
                'id' => $revision->syllabus->id,
                'subject' => $revision->syllabus->academicSubjectName(),
                'code' => $revision->syllabus->academicSubjectCode(),
                'period' => $revision->syllabus->convocation->process->academicPeriod->codigo,
            ],
            'revision' => [
                'id' => $revision->id,
                'number' => $revision->numero_revision,
                'approved_at' => $revision->approval->aprobado_en->toIso8601String(),
                'approved_by' => $revision->approval->approver->nombre,
            ],
            'artifacts' => $artifacts->map(fn (ExportArtifact $artifact): array => [
                'id' => $artifact->id,
                'estado' => $artifact->estado,
                'requested_at' => $artifact->solicitado_en->toIso8601String(),
                'completed_at' => $artifact->completado_en?->toIso8601String(),
                'renderer_label' => 'Formato institucional en revisión',
                'error' => $artifact->estado === 'fallido' ? 'No se pudo generar el PDF. Puede reintentar.' : null,
                'files' => $artifact->estado !== 'completado' || $artifact->pdfObject === null ? null : [
                    'pdf_size' => $artifact->pdfObject->tamano_bytes,
                ],
            ])->values(),
        ];

        return response()->json($payload);
    }

    public function store(
        SyllabusRevision $revision,
        RequestSyllabusExportRequest $request,
        RequestSyllabusExport $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $artifact = $action->execute(
            $revision,
            $request->string('idempotency_key')->toString(),
            $actor,
            $request,
        );

        $message = $artifact->estado === 'completado'
            ? 'El PDF ya estaba disponible.'
            : 'El PDF se está generando.';

        return response()->json(['message' => $message, 'artifact_id' => $artifact->id], 202);
    }

    public function download(
        ExportArtifact $artifact,
        DownloadExportRequest $request,
        ActiveRole $roles,
        RecordAuditEvent $audit,
    ): StreamedResponse {
        $object = $artifact->pdfObject;
        abort_unless($object !== null && $object->disco === 'private' && Storage::disk('private')->exists($object->ruta_interna), 404);
        $artifact->loadMissing('revision.syllabus.parallel.subject');
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $roles->resolve($request)?->id,
            action: 'documento.descargado',
            resourceType: 'artefacto_exportacion',
            resourceId: $artifact->id,
            result: 'exito',
            metadata: [
                'format' => 'pdf',
                'revision_number' => $artifact->revision->numero_revision,
                'object_id' => $object->id,
            ],
        );
        $code = Str::slug($artifact->revision->syllabus->academicSubjectCode()) ?: 'asignatura';

        return Storage::disk('private')->download($object->ruta_interna, "silabo-{$code}-revision-{$artifact->revision->numero_revision}.pdf", [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
