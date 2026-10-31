<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

final readonly class TemplateAssistantOutcome
{
    public const PROPOSAL = 'propuesta';

    public const ANSWER = 'respuesta';

    public const QUESTION = 'pregunta';

    public const FAILED = 'fallido';

    /**
     * @param  array{payload: array{version: int, sections: list<array{key: string, title: string, orientation: string, fields: list<array{key: string, label: string, quien_llena: string, content: array<string, mixed>}>}>}, changes: list<string>, changed: array{secciones: list<string>, campos: list<string>}, fingerprint: string}|null  $proposal
     * @param  list<array{paso: int, funcion: string, argumentos: string, resultado: string}>  $trace
     * @param  array{prompt: int, completion: int, cached: int}  $usage
     */
    public function __construct(
        public string $status,
        public string $message,
        public ?array $proposal,
        public ?string $errorCode,
        public array $trace,
        public array $usage,
        public int $steps,
        public string $gatewayVersion,
    ) {}
}
