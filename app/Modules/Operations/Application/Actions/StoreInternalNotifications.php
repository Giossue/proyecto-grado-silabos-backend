<?php

namespace App\Modules\Operations\Application\Actions;

use App\Models\User;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;

class StoreInternalNotifications
{
    /** @param list<string> $recipientIds */
    public function execute(
        string $eventType,
        string $deduplicationKey,
        array $recipientIds,
        string $title,
        string $message,
        string $resourceType,
        string $resourceId,
    ): int {
        $recipientIds = array_values(array_unique($recipientIds));
        if ($recipientIds === []) {
            return 0;
        }
        $activeIds = User::query()->whereIn('id', $recipientIds)->where('activo', true)->pluck('id');
        foreach ($activeIds as $recipientId) {
            InternalNotification::query()->firstOrCreate(
                ['usuario_id' => $recipientId, 'clave_deduplicacion' => 'flujo:'.$deduplicationKey],
                [
                    'tipo' => $eventType,
                    'titulo' => $title,
                    'mensaje' => $message,
                    'tipo_recurso' => $resourceType,
                    'recurso_id' => $resourceId,
                    'notificado_en' => now(),
                ],
            );
        }

        return $activeIds->count();
    }
}
