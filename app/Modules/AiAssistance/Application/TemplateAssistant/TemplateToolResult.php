<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

final readonly class TemplateToolResult
{
    public const ANSWER = 'responder';

    public const QUESTION = 'preguntar';

    public function __construct(
        public string $content,
        public bool $failed = false,
        public ?string $terminal = null,
        public ?string $message = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(array $data): self
    {
        return new self(self::encode($data));
    }

    public static function error(string $message): self
    {
        return new self(self::encode(['error' => $message]), failed: true);
    }

    public static function finish(string $terminal, string $message): self
    {
        return new self(self::encode(['ok' => true]), terminal: $terminal, message: $message);
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
