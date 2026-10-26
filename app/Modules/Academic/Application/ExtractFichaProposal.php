<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Contracts\FichaExtractionGateway;
use App\Modules\Academic\Domain\FichaExtractionResult;
use Throwable;

final class ExtractFichaProposal
{
    public function __construct(
        private readonly FichaEvidence $evidence,
        private readonly FichaFieldValues $values,
        private readonly FichaExtractionGateway $gateway,
    ) {}

    /**
     * @param  array{code: string, name: string, cycle: int|null}  $subject
     * @param  list<array{key: string, label: string, columns: list<string>, section?: array{key: string, label: string}, format?: string, template?: array<string, mixed>, components?: array<string, mixed>}>  $fields
     * @param  list<string|array{text: string, document: string, page: int|null, source?: int}>  $blocks
     * @param  list<array{name: string, code: string}>  $subjects
     * @return array<string, mixed>
     */
    public function execute(array $subject, array $fields, array $blocks, array $subjects, ?string $career = null): array
    {
        $started = microtime(true);
        $subject['career'] = $career;
        $fragments = $this->evidence->context($subject['name'], $subject['code'], $blocks, $subjects, $fields, $career);
        $values = [];
        $reasons = [];
        $sources = [];
        $attempts = 0;
        $failed = false;
        if ($fragments !== [] && $fields !== []) {
            $attempts++;
            try {
                $proposal = $this->gateway->propose($subject, $fields, $fragments);
                $this->merge($fields, $proposal, $values, $reasons, $sources);
            } catch (Throwable) {
                $failed = true;
            }
            $missing = array_values(array_filter($fields, fn (array $field): bool => ! array_key_exists($field['key'], $values)));
            if (! $failed && $missing !== []) {
                $recovery = [];
                foreach ($missing as $field) {
                    $budget = (int) floor(30000 / count($missing));
                    $pieces = $this->evidence->context($subject['name'], $subject['code'], $blocks, $subjects, [$field], $career, $field['label'].' '.implode(' ', $field['columns']).' '.($field['section']['label'] ?? ''), $budget);
                    $recovery = [...$recovery, ...$pieces];
                }
                $attempts++;
                try {
                    $proposal = $this->gateway->propose($subject, $missing, array_values(array_unique($recovery)));
                    $this->merge($missing, $proposal, $values, $reasons, $sources);
                } catch (Throwable) {
                    $failed = $values === [];
                    foreach ($missing as $field) {
                        $reasons[$field['key']] = 'servicio_no_disponible';
                    }
                }
            }
        }
        $missing = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field['key'], $values)) {
                $missing[] = ['key' => $field['key'], 'label' => $field['label'], 'reason' => $failed ? 'servicio_no_disponible' : ($reasons[$field['key']] ?? ($fragments === [] ? 'sin_contexto' : 'no_encontrado'))];
            }
        }

        return [
            'state' => $failed ? 'fallida' : ($values === [] ? 'sin_evidencia' : 'lista'),
            'values' => (object) $values,
            'sources' => (object) $sources,
            'missing' => $missing,
            'extraction' => ['attempts' => $attempts, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)],
        ];
    }

    /**
     * @param  list<array{key: string, label: string, columns: list<string>}>  $fields
     * @param  array<string, mixed>|FichaExtractionResult  $proposal
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $reasons
     * @param  array<string, list<array{id: string, document: string, page: int|null, scope: string}>>  $sources
     */
    private function merge(array $fields, array|FichaExtractionResult $proposal, array &$values, array &$reasons, array &$sources): void
    {
        $rawValues = $proposal instanceof FichaExtractionResult ? $proposal->values : $proposal;
        $invalid = $proposal instanceof FichaExtractionResult ? $proposal->invalid : [];
        $references = $proposal instanceof FichaExtractionResult ? $proposal->sources : [];
        foreach ($fields as $field) {
            if (in_array($field['key'], $invalid, true)) {
                $reasons[$field['key']] = 'estructura_invalida';

                continue;
            }
            $raw = $rawValues[$field['key']] ?? null;
            $clean = $this->values->clean($raw, $field['columns']);
            if ($clean !== null) {
                $values[$field['key']] = $clean;
                if (isset($references[$field['key']])) {
                    $sources[$field['key']] = $references[$field['key']];
                }
                unset($reasons[$field['key']]);
            } elseif ($raw !== null && $raw !== '' && $raw !== [] && $raw !== ['rows' => []]) {
                $reasons[$field['key']] = 'estructura_invalida';
            }
        }
    }
}
