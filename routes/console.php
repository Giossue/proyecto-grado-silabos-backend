<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auth:clear-resets')->everyFifteenMinutes()->withoutOverlapping();
// Resúmenes diarios: Coordinación (por revisar), Docente (por elaborar o corregir) y Biblioteca (por revisar).
foreach (['avisos:resumen-coordinacion', 'avisos:resumen-diario'] as $summary) {
    Schedule::command($summary)
        ->weekdays()
        ->dailyAt((string) config('notifications.digest.time'))
        ->timezone((string) config('app.display_timezone', 'America/Guayaquil'))
        ->withoutOverlapping();
}
Schedule::command('convocatorias:cerrar-vencidas')
    ->dailyAt('00:10')
    ->timezone((string) config('app.display_timezone', 'America/Guayaquil'))
    ->withoutOverlapping();
Schedule::command('convocatorias:avisar-cierre')
    ->dailyAt((string) config('notifications.digest.time'))
    ->timezone((string) config('app.display_timezone', 'America/Guayaquil'))
    ->withoutOverlapping();
