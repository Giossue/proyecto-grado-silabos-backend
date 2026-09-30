<?php

namespace App\Modules\AiAssistance\Presentation\Http;

/**
 * @phpstan-type Hint array{emotion: string, intensity: float}
 */
final class AvatarHint
{
    /** @return Hint|null */
    public static function forMessage(string $role, string $state): ?array
    {
        if ($role !== 'asistente') {
            return null;
        }

        return match ($state) {
            'completado' => ['emotion' => 'happy', 'intensity' => 0.4],
            'fallido' => ['emotion' => 'confused', 'intensity' => 0.7],
            default => null,
        };
    }
}
