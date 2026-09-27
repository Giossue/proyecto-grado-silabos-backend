<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Contracts\CurricularExtractionGateway;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularImport;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Support\CanonicalHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** One bounded extraction request per queue job; progress lives in PostgreSQL. */
final class PrepareCurricularImport
{
    public function __construct(
        private readonly CurricularExtractionGateway $gateway,
        private readonly CurricularSourceBlocks $reader,
        private readonly CurricularImportProposal $proposals,
        private readonly CanonicalHasher $hasher,
    ) {}

    public function step(string $importId): bool
    {
        $import = CurricularImport::query()->find($importId);
        if ($import === null || in_array($import->estado_importacion, ['lista', 'aplicada', 'fallida'], true)) {
            return true;
        }
        if ($import->solicitado_en->lt(now()->subHour())) {
            throw new RuntimeException('La importación superó el tiempo disponible.');
        }
        $documents = $import->documentos_importacion;
        $blocks = [];
        foreach ($documents as $index => $document) {
            $source = AcademicSource::query()->whereKey($document['source_id'])->where('carrera_id', $import->carrera_id)->where('activo', true)->first();
            if ($source === null || $source->estado_ingesta === 'fallida') {
                throw new RuntimeException('Una fuente no está disponible o su lectura falló.');
            }
            if (in_array($source->estado_ingesta, ['pendiente', 'en_ejecucion'], true)) {
                return false;
            }
            if (($document['kind'] ?? null) === 'pdf' && isset($document['original_object_id'])
                && $source->objeto_pdf_id !== $document['original_object_id']) {
                throw new RuntimeException('El archivo de una fuente cambió durante la importación.');
            }
            $hash = $this->hasher->hash($source->documento ?? []);
            if (isset($document['hash']) && $document['hash'] !== $hash) {
                throw new RuntimeException('Una fuente cambió durante la importación.');
            }
            $documents[$index]['hash'] = $hash;
            array_push($blocks, ...$this->reader->forSource($source));
        }
        if ($blocks === []) {
            throw new RuntimeException('No se encontró contenido legible para extraer.');
        }
        $execution = JobExecution::query()->where('tipo', 'curriculo.extraer')->where('recurso_id', $importId)->firstOrFail();
        $result = $execution->resultado ?? [];
        $cursor = (int) ($result['cursor'] ?? 0);
        $raw = is_array($result['proposal'] ?? null) ? $result['proposal'] : ['definitions' => [], 'values' => [], 'issues' => []];
        $chunk = [];
        $characters = 0;
        foreach (array_slice($blocks, $cursor) as $block) {
            $length = mb_strlen($block['text']);
            if ($length > (int) config('curricular_import.batch_characters')) {
                throw new RuntimeException('Un bloque es demasiado extenso para extraerlo completo.');
            }
            if ($chunk !== [] && $characters + $length > (int) config('curricular_import.batch_characters')) {
                break;
            }
            $chunk[] = $block;
            $characters += $length;
        }
        $import->update(['estado_importacion' => 'en_ejecucion', 'documentos_importacion' => $documents]);
        $execution->update(['estado' => 'en_ejecucion', 'iniciado_en' => $execution->iniciado_en ?? now(), 'intentos' => $execution->intentos + 1]);
        $subjects = Subject::query()->where('carrera_id', $import->carrera_id)->orderBy('codigo_asignatura')->get();
        $input = [
            'career' => $import->career->nombre,
            'instruction' => $import->instrucciones_importacion,
            'subjects' => $subjects->map(fn (Subject $subject): array => ['code' => $subject->codigo_asignatura, 'name' => $subject->nombre, 'organization_unit' => $subject->unidad_organizacion_curricular?->label()])->all(),
            'known_concepts' => CurricularConcept::query()->orderBy('clave_concepto')->get()->map(fn (CurricularConcept $concept): array => [
                'key' => $concept->clave_concepto, 'label' => $concept->etiqueta_concepto, 'type' => $concept->tipo_concepto, 'scope' => $concept->ambito_concepto, 'columns' => $concept->columnas_concepto,
            ])->all(),
            'earlier_definitions' => $raw['definitions'],
            'blocks' => $chunk,
        ];
        $partial = $this->gateway->extract($input);
        foreach (['definitions', 'values', 'issues'] as $key) {
            if (! is_array($partial[$key] ?? null) || ! array_is_list($partial[$key])) {
                throw new RuntimeException('La respuesta de extracción no contiene las colecciones esperadas.');
            }
            $raw[$key] = [...$raw[$key], ...$partial[$key]];
        }
        if (count($raw['values']) > 10000 || count($raw['definitions']) > 1000 || count($raw['issues']) > 1000) {
            throw new RuntimeException('La propuesta excedió los límites de importación.');
        }
        $next = $cursor + count($chunk);
        $complete = $next >= count($blocks);
        $proposal = $complete ? $this->normalize($raw, $blocks, array_values($subjects->all())) : null;

        return DB::transaction(function () use ($complete, $cursor, $documents, $execution, $import, $next, $proposal, $raw, $blocks): bool {
            $locked = CurricularImport::query()->lockForUpdate()->findOrFail($import->id);
            $job = JobExecution::query()->lockForUpdate()->findOrFail($execution->id);
            if (in_array($locked->estado_importacion, ['lista', 'aplicada', 'fallida'], true)) {
                return true;
            }
            if ((int) ($job->resultado['cursor'] ?? 0) !== $cursor) {
                return false;
            }
            foreach ($documents as $document) {
                $source = AcademicSource::query()->where('carrera_id', $locked->carrera_id)->whereKey($document['source_id'])->lockForUpdate()->firstOrFail();
                if (! $source->activo || in_array($source->estado_ingesta, ['pendiente', 'en_ejecucion', 'fallida'], true)
                    || (($document['kind'] ?? null) === 'pdf' && isset($document['original_object_id'])
                        && $source->objeto_pdf_id !== $document['original_object_id'])
                    || ! hash_equals($document['hash'], $this->hasher->hash($source->documento ?? []))) {
                    throw new RuntimeException('Una fuente cambió antes de guardar la propuesta.');
                }
            }
            $job->update([
                'resultado' => ['cursor' => $next, 'block_count' => count($blocks), 'proposal' => $complete ? null : $raw],
                'progreso' => $complete ? 100 : min(95, 10 + (int) (85 * $next / count($blocks))),
                'estado' => $complete ? 'completada' : 'en_ejecucion', 'finalizado_en' => $complete ? now() : null,
            ]);
            $locked->update(['estado_importacion' => $complete ? 'lista' : 'en_ejecucion', 'documentos_importacion' => $documents, 'propuesta_importacion' => $proposal]);

            return $complete;
        });
    }

    /** @param array<string, mixed> $raw
     * @param  list<array{id:string,source_id:string,source_name:string,locator:string,text:string}>  $blocks
     * @param  list<Subject>  $subjects
     * @return array<string, mixed>
     */
    public function normalize(array $raw, array $blocks, array $subjects): array
    {
        $definitions = [];
        foreach ($raw['definitions'] as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                throw new RuntimeException('La extracción contiene conceptos inválidos.');
            }
            $key = $definition['key'];
            if (isset($definitions[$key]) && (($definitions[$key]['type'] ?? null) !== ($definition['type'] ?? null) || ($definitions[$key]['scope'] ?? null) !== ($definition['scope'] ?? null) || ($definitions[$key]['columns'] ?? []) !== ($definition['columns'] ?? []))) {
                throw new RuntimeException('La extracción propuso estructuras incompatibles para un mismo concepto.');
            }
            $definitions[$key] = $definition;
        }
        $byId = array_column($blocks, null, 'id');
        $rows = [];
        foreach ($raw['values'] as $candidate) {
            if (! is_array($candidate) || ! is_string($candidate['concept_key'] ?? null) || ! array_key_exists('value', $candidate)) {
                throw new RuntimeException('La extracción contiene registros incompletos.');
            }
            $definition = $definitions[$candidate['concept_key']] ?? null;
            if ($definition === null) {
                throw new RuntimeException('Un dato extraído no declara su estructura.');
            }
            $evidence = [];
            $invalidEvidence = false;
            foreach (is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : [] as $citation) {
                $block = is_array($citation) && is_string($citation['block_id'] ?? null) ? ($byId[$citation['block_id']] ?? null) : null;
                $quote = is_array($citation) ? ($citation['quote'] ?? null) : null;
                if ($block === null || ! is_string($quote) || mb_strlen(trim($quote)) < 4 || mb_strlen($quote) > 20000 || ! $this->contains($block['text'], $quote)) {
                    $invalidEvidence = true;

                    continue;
                }
                $evidence[] = ['source_id' => $block['source_id'], 'locator' => $block['locator'], 'quote' => $quote];
            }
            $quoted = $this->comparable(implode(' ', array_column($evidence, 'quote')));
            foreach ($this->leaves($candidate['value']) as $leaf) {
                // Labels plus definitions may be copied from separate evidence blocks.
                foreach (preg_split('/\s+[—–]\s+/u', $leaf) ?: [$leaf] as $part) {
                    if (trim($part) !== '' && ! $this->contains($quoted, $part)) {
                        $invalidEvidence = true;
                    }
                }
            }
            $subject = null;
            if (($definition['scope'] ?? null) === 'asignatura') {
                $code = is_string($candidate['subject_code'] ?? null) ? $this->comparable($candidate['subject_code']) : '';
                $name = is_string($candidate['subject_name'] ?? null) ? $this->comparable($candidate['subject_name']) : '';
                $matches = array_values(array_filter($subjects, fn (Subject $item): bool => $code !== ''
                    ? $this->comparable($item->codigo_asignatura) === $code
                    : $name !== '' && $this->comparable($item->nombre) === $name));
                if (count($matches) === 1) {
                    $subject = $matches[0];
                    if ($code !== '' && $name !== '' && $this->comparable($subject->nombre) !== $name) {
                        $subject = null;
                    }
                }
            }
            $issue = is_string($candidate['issue'] ?? null) && trim($candidate['issue']) !== '' ? $candidate['issue'] : null;
            $issueCode = $issue !== null ? 'source_conflict' : null;
            if ($invalidEvidence || $evidence === []) {
                $issueCode = 'invalid_evidence';
                $issue = 'El contenido no pudo verificarse completamente en los extractos de la fuente.';
            } elseif (($definition['scope'] ?? null) === 'asignatura' && $subject === null && $issueCode === null) {
                $issueCode = 'subject_unmatched';
                $issue = 'Seleccione la materia que corresponde al nombre encontrado en el documento.';
            }
            $row = [
                'id' => (string) Str::uuid(), 'concept_key' => $candidate['concept_key'],
                'subject_id' => $subject?->id, 'subject_name' => $subject->nombre ?? (is_string($candidate['subject_name'] ?? null) ? $candidate['subject_name'] : null),
                'value' => $candidate['value'], 'evidence' => $evidence, 'issue' => $issue, 'issue_code' => $issueCode,
            ];
            // Exact repeated extraction is idempotent. Different source values remain visible.
            $rowKey = $this->hasher->hash([$row['concept_key'], $row['subject_id'], $row['subject_name'], $row['value'], $row['issue_code']]);
            if (isset($rows[$rowKey])) {
                $rows[$rowKey]['evidence'] = array_values(array_unique([...$rows[$rowKey]['evidence'], ...$evidence], SORT_REGULAR));
            } else {
                $rows[$rowKey] = $row;
            }
        }

        // Lists/tables split across pages in one source form one value. Different
        // documents retain separate alternatives for an explicit source decision.
        $merged = [];
        foreach ($rows as $row) {
            $sourceIds = array_values(array_unique(array_column($row['evidence'], 'source_id')));
            sort($sourceIds);
            $group = $this->hasher->hash([$row['concept_key'], $row['subject_id'], $row['subject_name'], $row['issue_code'], $sourceIds]);
            if (isset($merged[$group]) && is_array($row['value']) && array_is_list($row['value']) && is_array($merged[$group]['value'])) {
                $merged[$group]['value'] = array_values(array_unique([...$merged[$group]['value'], ...$row['value']], SORT_REGULAR));
                $merged[$group]['evidence'] = array_values(array_unique([...$merged[$group]['evidence'], ...$row['evidence']], SORT_REGULAR));
            } else {
                $merged[isset($merged[$group]) ? $group.':'.$row['id'] : $group] = $row;
            }
        }
        $destinations = [];
        foreach ($merged as $key => $row) {
            $target = $this->hasher->hash([$row['concept_key'], $row['subject_id'], $row['subject_name']]);
            $destinations[$target][] = $key;
        }
        foreach ($destinations as $keys) {
            if (count($keys) < 2) {
                continue;
            }
            foreach ($keys as $key) {
                if (in_array($merged[$key]['issue_code'], [null, 'subject_unmatched', 'source_conflict'], true)) {
                    $merged[$key]['issue_code'] = 'source_conflict';
                    $merged[$key]['issue'] = 'Hay valores distintos para este dato. Revise las fuentes y seleccione una sola alternativa.';
                }
            }
        }

        return $this->proposals->normalize(['definitions' => array_values($definitions), 'values' => array_values($merged), 'issues' => array_values(array_unique($raw['issues']))]);
    }

    /** @return list<string> */
    private function leaves(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        $result = [];
        if (is_array($value)) {
            foreach ($value as $child) {
                array_push($result, ...$this->leaves($child));
            }
        }

        return $result;
    }

    private function comparable(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', strtolower(Str::ascii($text))) ?? '');
    }

    private function contains(string $text, string $fragment): bool
    {
        $needle = $this->comparable($fragment);

        return $needle !== '' && str_contains(' '.$this->comparable($text).' ', ' '.$needle.' ');
    }

    public function fail(string $importId): void
    {
        DB::transaction(function () use ($importId): void {
            $import = CurricularImport::query()->lockForUpdate()->find($importId);
            if ($import === null || in_array($import->estado_importacion, ['lista', 'aplicada'], true)) {
                return;
            }
            $message = 'No se pudo preparar la propuesta completa. Revise la disponibilidad de los servicios y las fuentes antes de reintentar.';
            $import->update(['estado_importacion' => 'fallida', 'mensaje_error_importacion' => $message]);
            JobExecution::query()->where('tipo', 'curriculo.extraer')->where('recurso_id', $importId)->update([
                'estado' => 'fallida', 'codigo_error' => 'extraccion_curricular_fallida', 'mensaje_error' => $message, 'finalizado_en' => now(),
            ]);
        });
    }
}
