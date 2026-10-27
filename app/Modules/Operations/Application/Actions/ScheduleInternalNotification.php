<?php

namespace App\Modules\Operations\Application\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ScheduleInternalNotification
{
    public function __construct(private readonly StoreInternalNotifications $store) {}

    /** @param  list<string>  $recipientIds */
    public function execute(
        string $eventType,
        string $deduplicationKey,
        array $recipientIds,
        string $title,
        string $message,
        string $resourceType,
        string $resourceId,
        ?string $periodId = null,
    ): void {
        $this->store->execute($eventType, $deduplicationKey, $recipientIds, $title, $message, $resourceType, $resourceId, $periodId);
    }

    public function date(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)
            ->setTimezone((string) config('app.display_timezone', 'America/Guayaquil'))
            ->translatedFormat('j \\d\\e F \\d\\e Y, H:i');
    }
}
