<?php

namespace App\Modules\Syllabus\Application;

/**
 * Resultado de validar el borrador de un sílabo. Se calcula en el momento y no se guarda.
 *
 * @phpstan-type Issue array{field_id: string, code: string, severity: string, message: string}
 */
final readonly class DraftValidation
{
    /** @param list<Issue> $results */
    public function __construct(
        public int $lockVersion,
        public array $results,
    ) {}

    public function blockingErrors(): int
    {
        return count(array_filter($this->results, fn (array $issue): bool => $issue['severity'] === 'error'));
    }

    public function warnings(): int
    {
        return count($this->results) - $this->blockingErrors();
    }

    /** @return array{version_bloqueo: int, blocking_errors: int, warnings: int, results: list<Issue>} */
    public function toArray(): array
    {
        return [
            'version_bloqueo' => $this->lockVersion,
            'blocking_errors' => $this->blockingErrors(),
            'warnings' => $this->warnings(),
            'results' => $this->results,
        ];
    }
}
