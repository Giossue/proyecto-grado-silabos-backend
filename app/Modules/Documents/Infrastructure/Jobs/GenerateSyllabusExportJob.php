<?php

namespace App\Modules\Documents\Infrastructure\Jobs;

use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Documents\Application\DocumentBundleValidator;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use App\Modules\Documents\Domain\Data\RenderedDocument;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CoordinationReview;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\Queue\RunsOnce;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[UniqueFor(900)]
class GenerateSyllabusExportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnce, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $artifactId)
    {
        $this->onQueue('documentos');
    }

    public function uniqueId(): string
    {
        return $this->artifactId;
    }

    public function handle(
        DocumentRenderer $renderer,
        RecordAuditEvent $audit,
        DocumentBundleValidator $validator,
    ): void {
        $shouldContinue = DB::transaction(function (): bool {
            $artifact = ExportArtifact::query()->lockForUpdate()->find($this->artifactId);
            if ($artifact === null || $artifact->estado === 'completado') {
                return false;
            }
            $artifact->update(['estado' => 'en_ejecucion']);

            return true;
        });
        if (! $shouldContinue) {
            return;
        }

        $artifact = ExportArtifact::query()->with([
            'revision',
            'syllabus.parallel.subject',
            'syllabus.convocation.process.academicPeriod',
        ])->findOrFail($this->artifactId);
        $approval = Approval::query()->with('approver')->where('revision_silabo_id', $artifact->revision_silabo_id)->first();
        if ($approval === null) {
            throw new RuntimeException('La revisión dejó de estar aprobada.');
        }

        $syllabus = $artifact->syllabus;
        $revision = $artifact->revision;
        $bundle = $renderer->render(new DocumentRenderInput(
            subject: $syllabus->academicSubjectName(),
            subjectCode: $syllabus->academicSubjectCode(),
            academicPeriod: $syllabus->convocation->process->academicPeriod->codigo,
            revisionNumber: $revision->numero_revision,
            revisionFingerprint: $revision->huella_sha256,
            generatedAt: $artifact->solicitado_en->toIso8601String(),
            locale: 'es-EC',
            snapshot: $this->withSignatures($revision, $approval),
        ));
        $validator->validate($bundle);

        $basePath = "exports/{$revision->id}/{$artifact->id}";
        $docx = $this->persistObject($artifact, $bundle->docx, "{$basePath}/syllabus.docx");
        $pdf = $this->persistObject($artifact, $bundle->pdf, "{$basePath}/syllabus.pdf");

        DB::transaction(function () use ($artifact, $audit, $docx, $pdf, $revision, $syllabus): void {
            $locked = ExportArtifact::query()->lockForUpdate()->findOrFail($artifact->id);
            if ($locked->estado === 'completado') {
                return;
            }

            $locked->update([
                'objeto_docx_id' => $docx->id,
                'objeto_pdf_id' => $pdf->id,
                'estado' => 'completado',
                'completado_en' => now(),
            ]);
            app(ScheduleWorkflowNotification::class)->execute(
                syllabus: $syllabus,
                eventType: 'silabo.aprobado',
                deduplicationKey: "silabo.aprobado:{$revision->id}",
                recipientIds: app(WorkflowNotificationRecipients::class)->teachersFor($syllabus),
                revisionNumber: $revision->numero_revision,
                artifactId: $locked->id,
            );
            $audit->execute(
                actorId: null,
                roleAssignmentId: null,
                action: 'documento.exportacion_completada',
                resourceType: 'artefacto_exportacion',
                resourceId: $locked->id,
                result: 'exito',
                metadata: [
                    'revision_number' => $revision->numero_revision,
                    'docx_object_id' => $docx->id,
                    'pdf_object_id' => $pdf->id,
                ],
            );
        });
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function (): void {
            $artifact = ExportArtifact::query()->lockForUpdate()->find($this->artifactId);
            if ($artifact === null || $artifact->estado === 'completado') {
                return;
            }
            $artifact->update(['estado' => 'fallido']);
            app(RecordAuditEvent::class)->execute(
                actorId: null,
                roleAssignmentId: null,
                action: 'documento.exportacion_fallida',
                resourceType: 'artefacto_exportacion',
                resourceId: $artifact->id,
                result: 'fallido',
                metadata: ['error_code' => 'exportacion_documento_fallida'],
            );
            app(ScheduleWorkflowNotification::class)->execute(
                syllabus: $artifact->syllabus,
                eventType: 'documento.exportacion_fallida',
                deduplicationKey: "documento.exportacion_fallida:{$artifact->id}",
                recipientIds: app(WorkflowNotificationRecipients::class)->administrators(),
                revisionNumber: $artifact->revision->numero_revision,
                artifactId: $artifact->id,
            );
        });
    }

    private function persistObject(
        ExportArtifact $artifact,
        RenderedDocument $document,
        string $path,
    ): StoredObject {
        $attributes = [
            'nombre_logico' => "silabo-revision-{$artifact->revision->numero_revision}.{$document->extension}",
            'mime' => $document->mime,
            'tamano_bytes' => $document->size(),
            'huella_sha256' => $document->fingerprint(),
            'clasificacion' => 'syllabus_export',
            'propietario_usuario_id' => $artifact->solicitado_por,
            'almacenado_en' => now(),
        ];
        $existing = StoredObject::query()->where('disco', 'private')->where('ruta_interna', $path)->first();
        if ($existing !== null && ! $document->reproducible) {
            return $existing;
        }
        if ($existing !== null) {
            $this->assertObjectMatches($existing, $document);
        }

        Storage::disk('private')->put($path, $document->bytes);
        $object = StoredObject::query()->firstOrCreate(
            ['disco' => 'private', 'ruta_interna' => $path],
            $attributes,
        );
        $this->assertObjectMatches($object, $document);

        return $object;
    }

    private function assertObjectMatches(StoredObject $object, RenderedDocument $document): void
    {
        if ($object->mime !== $document->mime
            || $object->tamano_bytes !== $document->size()
            || ! hash_equals($object->huella_sha256, $document->fingerprint())) {
            throw new RuntimeException('El objeto privado existente no coincide con la exportación esperada.');
        }
    }

    /** @return array<string, mixed> */
    private function withSignatures(SyllabusRevision $revision, Approval $approval): array
    {
        $snapshot = $revision->fotografia;
        $variables = is_array($snapshot['template_variables'] ?? null) ? $snapshot['template_variables'] : [];
        $review = CoordinationReview::query()
            ->with('reviewer:id,nombre')
            ->whereHas('revision', fn ($query) => $query->where('silabo_id', $revision->silabo_id)->where('numero_revision', '<=', $revision->numero_revision))
            ->latest('revisado_en')
            ->first();
        $snapshot['template_variables'] = [...$variables, ...TemplateVariables::approval([
            'submitted_on' => $revision->enviado_en->setTimezone('America/Guayaquil')->format('d-m-Y'),
            'coordinator' => $review?->reviewer->nombre ?? $approval->approver->nombre,
            'approved_on' => ($review->revisado_en ?? $approval->aprobado_en)->setTimezone('America/Guayaquil')->format('d-m-Y'),
        ])];

        return $snapshot;
    }
}
