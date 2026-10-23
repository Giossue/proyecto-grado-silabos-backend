<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Contracts\FichaExtractionGateway;
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
     * @param  list<array{key: string, label: string, columns: list<string>}>  $fields
     * @param  list<string|array{text: string, document: string, page: int|null, source?: int}>  $blocks
     * @param  list<array{name: string, code: string}>  $subjects
     * @return array<string, mixed>
     */
    public function execute(array $subject, array $fields, array $blocks, array $subjects, ?string $career = null): array
    {
        $started = microtime(true);
        $fragments = $this->evidence->for($subject['name'], $subject['code'], $blocks, $subjects, career: $career);
        $values = [];
        $reasons = [];
        $attempts = 0;
        $failed = false;
        if ($fragments !== [] && $fields !== []) {
            $attempts++;
            try {
                $proposal = $this->gateway->propose($subject, $fields, $fragments);
                $this->merge($fields, $proposal, $values, $reasons);
            } catch (Throwable) {
                $failed = true;
            }
            $missing = array_values(array_filter($fields, fn (array $field): bool => ! array_key_exists($field['key'], $values)));
            if (! $failed && $missing !== []) {
                $recovery = [];
                foreach ($missing as $field) {
                    $pieces = $this->evidence->for($subject['name'], $subject['code'], $blocks, $subjects, $field['label'].' '.implode(' ', $field['columns']), $career);
                    $budget = (int) floor(30000 / count($missing));
                    $used = 0;
                    foreach ($pieces as $piece) {
                        $piece = mb_substr($piece, 0, max(0, $budget - $used));
                        if ($piece !== '') {
                            $recovery[] = $piece;
                            $used += mb_strlen($piece);
                        }
                        if ($used >= $budget) {
                            break;
                        }
                    }
                }
                $attempts++;
                try {
                    $proposal = $this->gateway->propose($subject, $missing, array_values(array_unique($recovery)));
                    $this->merge($missing, $proposal, $values, $reasons);
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
            'missing' => $missing,
            'extraction' => ['attempts' => $attempts, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)],
        ];
    }

    /**
     * @param  list<array{key: string, label: string, columns: list<string>}>  $fields
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $reasons
     */
    private function merge(array $fields, array $proposal, array &$values, array &$reasons): void
    {
        foreach ($fields as $field) {
            $raw = $proposal[$field['key']] ?? null;
            $clean = $this->values->clean($raw, $field['columns']);
            if ($clean !== null) {
                $values[$field['key']] = $clean;
                unset($reasons[$field['key']]);
            } elseif ($raw !== null && $raw !== '' && $raw !== [] && $raw !== ['rows' => []]) {
                $reasons[$field['key']] = 'estructura_invalida';
            }
        }
    }
}
