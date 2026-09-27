<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\CurricularImportProposal;
use App\Modules\Academic\Application\CurricularSourceBlocks;
use App\Modules\Academic\Application\Queries\CurricularImportsViewData;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularImport;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Support\CanonicalHasher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ApplyCurricularImport
{
    public function __construct(
        private readonly CurricularImportsViewData $view,
        private readonly CurricularImportProposal $proposals,
        private readonly CurricularSourceBlocks $sourceBlocks,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
        private readonly CanonicalHasher $hasher,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array{expected_fingerprint: string, confirmed: bool, items: list<array{id: string, subject_id?: string|null, accept_conflict?: bool}>, confirm_purge?: bool} $data */
    public function execute(string $id, array $data, User $actor, Request $request): CurricularImport
    {
        $role = $this->view->role($request);

        return DB::transaction(function () use ($actor, $data, $id, $request, $role): CurricularImport {
            // Serializa importaciones distintas que puedan modificar la misma base.
            $career = Career::query()->where('activo', true)->lockForUpdate()->findOrFail($role->carrera_id);
            $import = CurricularImport::query()->where('carrera_id', $career->id)->lockForUpdate()->findOrFail($id);
            $this->locks->assertCareerEditable($career->id);
            if ($import->estado_importacion !== 'lista') {
                $this->conflict('importacion_curricular_no_disponible', 'La importación no está disponible para confirmar.');
            }
            if ($data['expected_fingerprint'] !== $this->view->fingerprint($import)) {
                $this->conflict('conflicto_importacion_curricular', 'La propuesta o la base curricular cambió. Vuelva a consultar la importación.');
            }
            $documents = $this->verifiedDocuments($import);
            $proposal = $this->proposals->normalize($import->propuesta_importacion ?? []);
            $rows = $this->selectedRows($proposal, $data['items'], $career->id, $documents);
            $changes = [];
            foreach ($rows as $row) {
                $definition = $row['definition'];
                // createOrFirst usa un savepoint ante una carrera que cree la misma
                // clave simultáneamente; después se compara siempre su estructura.
                $concept = CurricularConcept::query()->firstOrCreate(['clave_concepto' => $definition['key']], [
                    'etiqueta_concepto' => $definition['label'], 'tipo_concepto' => $definition['type'],
                    'ambito_concepto' => $definition['scope'], 'columnas_concepto' => $definition['columns'],
                ]);
                if ($concept->tipo_concepto !== $definition['type'] || $concept->ambito_concepto !== $definition['scope']
                    || $concept->columnas_concepto !== $definition['columns']) {
                    throw ValidationException::withMessages(['items' => 'La estructura de un concepto ya registrado es distinta. Revise la propuesta antes de confirmar.']);
                }
                $current = CurricularValue::query()->where('concepto_id', $concept->id)->where('carrera_id', $career->id)
                    ->where('asignatura_id', $row['subject_id'])->lockForUpdate()->first();
                if ($current !== null && $this->hasher->hash([$current->valor_curricular, $current->evidencia_curricular])
                    === $this->hasher->hash([$row['value'], $row['evidence']])) {
                    continue;
                }
                $changes[] = [...$row, 'concept' => $concept, 'current' => $current];
            }
            $save = function () use ($actor, $career, $changes, $data, $import, $request, $role, $rows): CurricularImport {
                foreach ($changes as $change) {
                    $value = $change['current'] ?? new CurricularValue([
                        'concepto_id' => $change['concept']->id, 'carrera_id' => $career->id, 'asignatura_id' => $change['subject_id'],
                    ]);
                    $value->fill([
                        'valor_curricular' => $change['value'], 'evidencia_curricular' => $change['evidence'],
                        'confirmado_por' => $actor->id, 'confirmado_en' => now(),
                    ])->save();
                }
                $import->update([
                    'estado_importacion' => 'aplicada', 'aplicado_en' => now(),
                    'seleccion_aplicada' => array_map(fn (array $row): array => [
                        'id' => $row['id'], 'subject_id' => $row['subject_id'], 'accept_conflict' => $row['accept_conflict'],
                    ], $rows),
                ]);
                $this->audit->execute(
                    actorId: $actor->id, roleAssignmentId: $role->id,
                    action: 'academico.importacion_curricular.aplicada', resourceType: 'importacion_curricular', resourceId: $import->id,
                    result: 'exito', metadata: [
                        'career_id' => $career->id, 'selected_count' => count($data['items']), 'changed_count' => count($changes),
                        'proposal_fingerprint' => $data['expected_fingerprint'],
                    ], correlationId: $request->attributes->getString('correlation_id') ?: null,
                );

                return $import;
            };
            if ($changes === []) {
                return $save();
            }
            $subjects = in_array(null, array_column($changes, 'subject_id'), true)
                ? null : array_values(array_unique(array_column($changes, 'subject_id')));

            return $this->work->replace($request, $save, $career->id,
                reason: 'cambiaron los datos curriculares confirmados desde sus documentos', subjectIds: $subjects);
        });
    }

    /** @return array<string, array{blocks: array<string, string>, hash: string, name: string}> */
    private function verifiedDocuments(CurricularImport $import): array
    {
        $documents = [];
        $metadata = $import->documentos_importacion;
        usort($metadata, fn (array $a, array $b): int => strcmp((string) ($a['source_id'] ?? ''), (string) ($b['source_id'] ?? '')));
        foreach ($metadata as $document) {
            $sourceId = $document['source_id'] ?? null;
            $expected = $document['hash'] ?? null;
            if (! is_string($sourceId) || ! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/', $expected)) {
                $this->conflict('fuente_importacion_cambiada', 'La propuesta no dispone de una fotografía verificable de sus fuentes.');
            }
            $source = AcademicSource::query()->where('carrera_id', $import->carrera_id)->where('activo', true)
                ->lockForUpdate()->find($sourceId);
            if ($source === null || in_array($source->estado_ingesta, ['pendiente', 'en_ejecucion', 'fallida'], true)
                || (($document['kind'] ?? null) === 'pdf' && isset($document['original_object_id'])
                    && $source->objeto_pdf_id !== $document['original_object_id'])
                || ! hash_equals($expected, $this->hasher->hash($source->documento))) {
                $this->conflict('fuente_importacion_cambiada', 'Una fuente cambió o dejó de estar disponible. Genere otra propuesta con la fuente vigente.');
            }
            $documents[$sourceId] = [
                'blocks' => array_column($this->sourceBlocks->forSource($source), 'text', 'locator'),
                'hash' => $expected, 'name' => $source->nombre,
            ];
        }
        if ($documents === []) {
            $this->conflict('fuente_importacion_cambiada', 'La propuesta no contiene fuentes verificables.');
        }

        return $documents;
    }

    /** @param array<string, mixed> $proposal
     * @param  list<array{id: string, subject_id?: string|null, accept_conflict?: bool}>  $items
     * @param  array<string, array{blocks: array<string, string>, hash: string, name: string}>  $documents
     * @return list<array<string, mixed>>
     */
    private function selectedRows(array $proposal, array $items, string $careerId, array $documents): array
    {
        $definitions = array_column($proposal['definitions'], null, 'key');
        $available = array_column($proposal['values'], null, 'id');
        $selected = [];
        $targets = [];
        foreach ($items as $index => $item) {
            $row = $available[$item['id']] ?? null;
            if ($row === null) {
                throw ValidationException::withMessages(["items.{$index}.id" => 'El dato no pertenece a esta propuesta.']);
            }
            $definition = $definitions[$row['concept_key']];
            $subjectId = array_key_exists('subject_id', $item) ? $item['subject_id'] : $row['subject_id'];
            if ($definition['scope'] === 'asignatura') {
                if ($subjectId === null) {
                    throw ValidationException::withMessages(["items.{$index}.subject_id" => 'Seleccione la asignatura de este dato.']);
                }
                Subject::query()->where('carrera_id', $careerId)->lockForUpdate()->findOrFail($subjectId);
                if ($row['subject_id'] !== null && $subjectId !== $row['subject_id']) {
                    throw ValidationException::withMessages(["items.{$index}.subject_id" => 'La asignatura ya fue identificada en la propuesta. No puede redirigir este dato a otra materia.']);
                }
            } elseif ($subjectId !== null) {
                throw ValidationException::withMessages(["items.{$index}.subject_id" => 'Este dato corresponde a la carrera completa.']);
            }
            $resolved = ($row['issue_code'] === 'subject_unmatched' && $subjectId !== null)
                || ($row['issue_code'] === 'source_conflict' && ($item['accept_conflict'] ?? false) === true);
            if ($row['issue_code'] !== null && ! $resolved) {
                throw ValidationException::withMessages(["items.{$index}" => 'Este dato tiene una excepción pendiente y no se puede confirmar.']);
            }
            $target = $definition['key'].':'.($subjectId ?? 'career');
            if (isset($targets[$target])) {
                throw ValidationException::withMessages(['items' => 'Hay varios datos seleccionados para el mismo concepto y destino. Seleccione solo uno.']);
            }
            $targets[$target] = true;
            $evidence = [];
            foreach ($row['evidence'] as $citation) {
                $document = $documents[$citation['source_id']] ?? null;
                $quote = $this->normalizedText($citation['quote']);
                $block = $document['blocks'][$citation['locator']] ?? null;
                if ($block === null || $quote === '' || ! str_contains(' '.$this->normalizedText($block).' ', ' '.$quote.' ')) {
                    throw ValidationException::withMessages(["items.{$index}" => 'El extracto citado no se encuentra en la fuente indicada.']);
                }
                $evidence[] = [...$citation, 'source_name' => $document['name'], 'source_hash' => $document['hash']];
            }
            if ($evidence === []) {
                throw ValidationException::withMessages(["items.{$index}" => 'El dato no dispone de evidencia verificable.']);
            }
            $selected[] = [
                'id' => $item['id'], 'accept_conflict' => $row['issue_code'] === 'source_conflict' && ($item['accept_conflict'] ?? false) === true,
                'definition' => $definition, 'subject_id' => $subjectId,
                'value' => $this->proposals->value($definition['type'], $definition['columns'], $row['value']), 'evidence' => $evidence,
            ];
        }

        return $selected;
    }

    private function normalizedText(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', strtolower(Str::ascii($text))) ?? '');
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], 409));
    }
}
