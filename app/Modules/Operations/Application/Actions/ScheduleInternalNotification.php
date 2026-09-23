<?php

namespace App\Modules\Operations\Application\Actions;

use App\Modules\Operations\Infrastructure\Jobs\DeliverInternalNotificationJob;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Support\CanonicalHasher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Programa un aviso interno con el texto ya redactado. Es el mismo mecanismo que
 * `ScheduleWorkflowNotification` —trabajo pendiente en la transacción del cambio y
 * entrega idempotente después del commit—, pero sin atarse a un sílabo: sirve para
 * convocatorias, procesos y cuentas (I-120).
 */
class ScheduleInternalNotification
{
    public function __construct(private readonly CanonicalHasher $hasher) {}

    /**
     * Devuelve `null` si no queda nadie a quien avisar: no se crea un trabajo vacío.
     *
     * @param  list<string>  $recipientIds
     */
    public function execute(
        string $eventType,
        string $deduplicationKey,
        array $recipientIds,
        string $title,
        string $message,
        string $resourceType,
        string $resourceId,
        ?string $correlationId,
    ): ?JobExecution {
        $recipientIds = array_values(array_unique($recipientIds));
        if ($recipientIds === []) {
            return null;
        }

        $entrada = [
            'tipo_evento' => $eventType,
            'destinatarios_ids' => $recipientIds,
            'titulo' => $title,
            'mensaje' => $message,
        ];
        $execution = JobExecution::query()->firstOrCreate(
            ['clave_idempotencia' => $deduplicationKey],
            [
                'tipo' => 'notificacion.interna',
                'cola' => 'notificaciones',
                'estado' => 'pendiente',
                'correlacion_id' => is_string($correlationId) && Str::isUuid($correlationId)
                    ? $correlationId
                    : (string) Str::uuid(),
                'tipo_recurso' => $resourceType,
                'recurso_id' => $resourceId,
                'entrada' => $entrada,
                'intentos' => 0,
                'intentos_maximos' => 5,
                'progreso' => 0,
            ],
        );
        if ($execution->tipo !== 'notificacion.interna'
            || $execution->tipo_recurso !== $resourceType
            || $execution->recurso_id !== $resourceId
            || $this->hasher->hash(array_intersect_key($execution->entrada ?? [], $entrada))
                !== $this->hasher->hash($entrada)) {
            throw new RuntimeException('La clave del trabajo ya identifica otro aviso.');
        }
        if ($execution->wasRecentlyCreated) {
            DeliverInternalNotificationJob::dispatch($execution->id)->afterCommit();
        }

        return $execution;
    }

    /** Fecha legible para el mensaje, en la hora de Ecuador: «30 de septiembre de 2026, 23:59». */
    public function date(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)
            ->setTimezone((string) config('app.display_timezone', 'America/Guayaquil'))
            ->translatedFormat('j \\d\\e F \\d\\e Y, H:i');
    }
}
