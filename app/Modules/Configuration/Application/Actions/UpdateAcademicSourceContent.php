<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Application\IndexAcademicSource;
use App\Modules\Configuration\Application\AcademicSourceDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Support\CanonicalHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateAcademicSourceContent
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly CanonicalHasher $hasher,
        private readonly IndexAcademicSource $indexer,
        private readonly AcademicSourceDocument $documents,
    ) {}

    /** @param array<string, mixed> $document */
    public function execute(AcademicSource $source, array $document, User $actor, Request $request): AcademicSource
    {
        $activeRole = $this->roles->resolve($request);
        // Con una convocatoria en curso los sílabos se apoyan en las fuentes: se pausa antes.
        $this->locks->assertCareerEditable($source->carrera_id);

        $document = $this->documents->normalize($document);
        $content = $this->documents->text($document);

        return DB::transaction(function () use ($actor, $activeRole, $content, $document, $request, $source): AcademicSource {
            $locked = AcademicSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'contenido' => $content,
                'documento' => $document,
                'version_documento' => 1,
                'estado_ingesta' => 'manual',
                'ingesta_actualizada_en' => now(),
            ]);
            $fragmentCount = $this->indexer->execute($locked);

            // La auditoría registra la huella, no el documento: basta para probar qué
            // contenido regía sin copiar la fuente completa al historial.
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'fuente.contenido_actualizado',
                resourceType: 'fuente_academica',
                resourceId: $locked->id,
                result: 'exito',
                metadata: [
                    'fingerprint' => $this->hasher->hash($document),
                    'fragment_count' => $fragmentCount,
                ],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $locked;
        });
    }
}
