<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Operations\Application\EmailNoticeCatalog;
use App\Modules\Operations\Application\RoleDailySummaries;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Operations\Infrastructure\Mail\DailySummaryMail;
use App\Support\InstitutionDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendRoleDailySummaries extends Command
{
    protected $signature = 'avisos:resumen-diario';

    protected $description = 'Envía a cada docente y a Biblioteca un solo correo con lo pendiente, solo si hay algo y si no lo apagaron';

    public function handle(RoleDailySummaries $summaries, WorkflowNotificationRecipients $recipients, EmailNoticeCatalog $catalog): int
    {
        if (! config('notifications.digest.enabled') || ! config('notifications.email.enabled')) {
            return self::SUCCESS;
        }
        $base = rtrim((string) config('app.frontend_url'), '/');
        $sent = 0;
        foreach ($summaries->teachers() as $userId => $lines) {
            $sent += $this->send((string) $userId, EmailNoticeCatalog::TEACHER_SUMMARY, $lines, 'Abrir mis sílabos', $base.'/docente/mis-silabos', $catalog);
        }
        $library = $summaries->library();
        if ($library !== []) {
            foreach ($recipients->librarians() as $userId) {
                $sent += $this->send($userId, EmailNoticeCatalog::LIBRARY_SUMMARY, $library, 'Abrir revisiones', $base.'/biblioteca/revisiones', $catalog);
            }
        }
        $this->info("Resumen enviado a {$sent} persona(s).");

        return self::SUCCESS;
    }

    /** @param list<string> $lines */
    private function send(string $userId, string $type, array $lines, string $button, string $url, EmailNoticeCatalog $catalog): int
    {
        $user = User::query()->whereKey($userId)->where('activo', true)->first();
        if (! $user instanceof User || ! $user->hasVerifiedEmail() || ! $catalog->wants($user, $type)) {
            return 0;
        }
        // Un correo por persona, tipo y día, aunque el programador se reinicie.
        if (! Cache::add("avisos:{$type}:{$user->id}:".InstitutionDate::todayOn(), true, now()->addHours(30))) {
            return 0;
        }
        Mail::to($user->correo_electronico)->queue(new DailySummaryMail($user->nombre, $lines, $button, $url));

        return 1;
    }
}
