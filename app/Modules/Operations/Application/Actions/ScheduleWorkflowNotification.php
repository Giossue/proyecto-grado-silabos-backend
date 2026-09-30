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
        $entrada = [
            'tipo_evento' => $eventType,
            'destinatarios_ids' => array_values(array_unique($recipientIds)),
            'numero_revision' => $revisionNumber,
            'silabo_id' => $syllabus->id,
        ];
        $execution = JobExecution::query()->firstOrCreate(
            ['clave_idempotencia' => $deduplicationKey],
            [
                'tipo' => 'notificacion.interna',
                'cola' => 'notificaciones',
                'estado' => 'pendiente',
                'tipo_recurso' => 'silabo',
                'recurso_id' => $syllabus->id,
                'entrada' => $entrada,
                'intentos' => 0,
                'intentos_maximos' => 5,
                'progreso' => 0,
            ],
        );
        if ($execution->tipo !== 'notificacion.interna'
            || $execution->tipo_recurso !== 'silabo'
            || $execution->recurso_id !== $syllabus->id
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
