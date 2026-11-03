<?php

namespace App\Console\Commands;

use App\Modules\Operations\Application\CoordinatorDailySummary;
use App\Modules\Operations\Application\EmailNoticeCatalog;
use App\Modules\Operations\Infrastructure\Mail\CoordinatorSummaryMail;
use App\Support\InstitutionDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendCoordinatorDailySummary extends Command
{
    protected $signature = 'avisos:resumen-coordinacion';

    protected $description = 'Envía a cada coordinador un solo correo con sus sílabos por revisar, solo si tiene algo pendiente';

    public function handle(CoordinatorDailySummary $summary, EmailNoticeCatalog $catalog): int
    {
        if (! config('notifications.digest.enabled')) {
            return self::SUCCESS;
        }
        $pending = $summary->pendingByCoordinator();
        $sent = 0;
        foreach ($summary->usersOf(array_map('strval', array_keys($pending))) as $user) {
            if (! $catalog->wants($user, EmailNoticeCatalog::DAILY_SUMMARY)) {
                continue;
            }
            // Un correo por persona y día, aunque el programador se reinicie.
            if (! Cache::add('avisos:resumen-coordinacion:'.$user->id.':'.InstitutionDate::todayOn(), true, now()->addHours(30))) {
                continue;
            }
            Mail::to($user->correo_electronico)->queue(new CoordinatorSummaryMail(
                name: $user->nombre,
                careers: $pending[$user->id],
                url: rtrim((string) config('app.frontend_url'), '/').'/coordinacion/revisiones',
            ));
            $sent++;
        }
        $this->info("Resumen enviado a {$sent} coordinador(es).");

        return self::SUCCESS;
    }
}
