<?php

namespace App\Modules\Operations\Application\Actions;

use App\Models\User;
use App\Modules\Operations\Application\NotificationPeriod;
use App\Modules\Operations\Infrastructure\Mail\WorkflowNotificationMail;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class StoreInternalNotifications
{
    public function __construct(private readonly NotificationPeriod $periods) {}

    /** @param list<string> $recipientIds */
    public function execute(
        string $eventType,
        string $deduplicationKey,
        array $recipientIds,
        string $title,
        string $message,
        string $resourceType,
        string $resourceId,
        ?string $periodId = null,
    ): int {
        $recipientIds = array_values(array_unique($recipientIds));
        if ($recipientIds === []) {
            return 0;
        }
        $recipients = User::query()->whereIn('id', $recipientIds)->where('activo', true)->get();
        $periodId ??= $this->periods->of($resourceType, $resourceId);
        foreach ($recipients as $recipient) {
            $notification = InternalNotification::query()->firstOrCreate(
                ['usuario_id' => $recipient->id, 'clave_deduplicacion' => 'flujo:'.$deduplicationKey],
                [
                    'tipo' => $eventType,
                    'titulo' => $title,
                    'mensaje' => $message,
                    'tipo_recurso' => $resourceType,
                    'recurso_id' => $resourceId,
                    'periodo_academico_id' => $periodId,
                    'notificado_en' => now(),
                ],
            );
            if ($notification->wasRecentlyCreated) {
                $this->email($recipient, $eventType, $title, $message);
            }
        }

        return $recipients->count();
    }

    // Un correo por aviso y persona, solo para los avisos que piden actuar y a cuentas con correo verificado.
    private function email(User $recipient, string $eventType, string $title, string $message): void
    {
        if (! config('notifications.email.enabled')
            || ! in_array($eventType, (array) config('notifications.email.events'), true)
            || ! $recipient->hasVerifiedEmail()) {
            return;
        }
        $url = rtrim((string) config('app.frontend_url'), '/').'/';
        DB::afterCommit(fn () => Mail::to($recipient->correo_electronico)->queue(new WorkflowNotificationMail(
            name: $recipient->nombre,
            title: $title,
            body: $message,
            url: $url,
        )));
    }
}
