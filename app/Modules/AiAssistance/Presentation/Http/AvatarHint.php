<?php

namespace App\Modules\AiAssistance\Presentation\Http;

/**
 * Expresión del avatar del asistente derivada del estado real de la revisión o
 * del mensaje. Es determinista: nunca se infiere del texto generado por el modelo.
 *
 * @phpstan-type Hint array{emotion: string, intensity: float}
 */
final class AvatarHint
{
    /** @return Hint|null */
    public static function forReview(string $state, int $withRecommendations, int $failed): ?array
    {
        return match ($state) {
            'completada' => $withRecommendations === 0
                ? ['emotion' => 'happy', 'intensity' => 0.9]
                : ['emotion' => 'happy', 'intensity' => 0.5],
            'parcial' => ['emotion' => 'surprised', 'intensity' => 0.6],
            'no_concluyente', 'fallida' => ['emotion' => 'confused', 'intensity' => $failed > 0 ? 0.8 : 0.6],
            default => null,
        };
    }

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
