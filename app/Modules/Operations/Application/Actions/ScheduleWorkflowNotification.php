<?php

namespace App\Modules\Operations\Application\Actions;

use App\Modules\Operations\Infrastructure\Jobs\DeliverInternalNotificationJob;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\CanonicalHasher;
use RuntimeException;

class ScheduleWorkflowNotification
{
    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @param list<string> $recipientIds */
    public function execute(
        Syllabus $syllabus,
        string $eventType,
        string $deduplicationKey,
        array $recipientIds,
        ?int $revisionNumber,
    ): JobExecution {
        return $this->schedule($syllabus, $eventType, $deduplicationKey, $recipientIds, $revisionNumber, 'silabo', $syllabus->id);
    }

    /** @param list<string> $recipientIds */
    public function executeForResource(
        string $eventType,
        string $deduplicationKey,
        array $recipientIds,
        ?int $revisionNumber,
        string $resourceType,
        string $resourceId,
    ): JobExecution {
        return $this->schedule(null, $eventType, $deduplicationKey, $recipientIds, $revisionNumber, $resourceType, $resourceId);
    }

    /** @param list<string> $recipientIds */
    private function schedule(
        ?Syllabus $syllabus,
        string $eventType,
        string $deduplicationKey,
        array $recipientIds,
        ?int $revisionNumber,
        string $resourceType,
        string $resourceId,
    ): JobExecution {
        $entrada = [
            'tipo_evento' => $eventType,
            'destinatarios_ids' => array_values(array_unique($recipientIds)),
            'numero_revision' => $revisionNumber,
            'silabo_id' => $syllabus?->id,
        ];
        $execution = JobExecution::query()->firstOrCreate(
            ['clave_idempotencia' => $deduplicationKey],
            [
                'tipo' => 'notificacion.interna',
                'cola' => 'notificaciones',
                'estado' => 'pendiente',
                'tipo_recurso' => $resourceType,
                'recurso_id' => $resourceId,
                'entrada' => $entrada,
                'intentos' => 0,
            ],
        );
        if ($execution->tipo !== 'notificacion.interna'
            || $execution->tipo_recurso !== $resourceType
            || $execution->recurso_id !== $resourceId
            || $this->hasher->hash(array_intersect_key($execution->entrada ?? [], $entrada))
                !== $this->hasher->hash($entrada)) {
            throw new RuntimeException('La clave del trabajo ya identifica otro cambio de dominio.');
        }
        if ($execution->wasRecentlyCreated) {
            DeliverInternalNotificationJob::dispatch($execution->id)->afterCommit();
        }

        return $execution;
    }
}
