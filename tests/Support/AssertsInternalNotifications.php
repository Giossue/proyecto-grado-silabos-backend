<?php

namespace Tests\Support;

use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;

trait AssertsInternalNotifications
{
    private function assertNotified(string $userId, string $type, ?string $messageContains = null): InternalNotification
    {
        $notifications = InternalNotification::query()->where('usuario_id', $userId)->where('tipo', $type)->get();
        $this->assertCount(1, $notifications, "Se esperaba un aviso «{$type}» para el usuario.");
        $notification = $notifications->firstOrFail();
        if ($messageContains !== null) {
            $this->assertStringContainsString($messageContains, $notification->mensaje);
        }

        return $notification;
    }

    private function assertNotNotified(string $userId, string $type): void
    {
        $this->assertFalse(
            InternalNotification::query()->where('usuario_id', $userId)->where('tipo', $type)->exists(),
            "No se esperaba un aviso «{$type}» para el usuario.",
        );
    }
}
