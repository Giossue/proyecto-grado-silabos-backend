<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use Illuminate\Support\Collection;

/** Límite de uso del asistente por sílabo y docente: 20 preguntas y 3 chats en total. */
final class SyllabusAiLimits
{
    public const MAX_MESSAGES = 20;

    public const MAX_SESSIONS = 3;

    /**
     * @param  Collection<int, AiHistoryEntry>  $history  mensajes y marcas de «Nuevo chat», en orden
     * @return array{messages_used: int, messages_max: int, sessions_used: int, sessions_max: int}
     */
    public static function usage(Collection $history): array
    {
        $questions = $history->filter(fn (AiHistoryEntry $entry): bool => $entry->type() === 'mensaje' && $entry->part('rol') === 'usuario')->count();
        $restarts = $history->filter(fn (AiHistoryEntry $entry): bool => $entry->type() === 'ocultamiento')->count();
        $current = $history->isEmpty() || $history->last()->type() === 'ocultamiento' ? 0 : 1;

        return [
            'messages_used' => $questions,
            'messages_max' => self::MAX_MESSAGES,
            'sessions_used' => $restarts + $current,
            'sessions_max' => self::MAX_SESSIONS,
        ];
    }
}
