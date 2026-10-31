<?php

namespace App\Modules\Operations\Application\Actions;

use App\Modules\Operations\Application\WorkflowNotificationMessages;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;

class ScheduleWorkflowNotification
{
    public function __construct(private readonly StoreInternalNotifications $store) {}

    /** @param list<string> $recipientIds */
    public function execute(
        Syllabus $syllabus,
        string $eventType,
        string $deduplicationKey,
        array $recipientIds,
        ?int $revisionNumber,
        ?string $artifactId = null,
        ?string $reason = null,
    ): void {
        [$title, $message] = WorkflowNotificationMessages::for($eventType, $syllabus, $revisionNumber, $reason);
        $this->store->execute(
            $eventType,
            $deduplicationKey,
            $recipientIds,
            $title,
            $message,
            $artifactId === null ? 'silabo' : 'artefacto_exportacion',
            $artifactId ?? $syllabus->id,
        );
    }

    /** @param list<string> $recipientIds */
    public function executeForResource(
        string $eventType,
        string $deduplicationKey,
        array $recipientIds,
        ?int $revisionNumber,
        string $resourceType,
        string $resourceId,
    ): void {
        [$title, $message] = WorkflowNotificationMessages::for($eventType, null, $revisionNumber);
        $this->store->execute($eventType, $deduplicationKey, $recipientIds, $title, $message, $resourceType, $resourceId);
    }
}
