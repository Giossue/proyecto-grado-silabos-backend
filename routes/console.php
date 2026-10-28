<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auth:clear-resets')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('avisos:resumen-coordinacion')
    ->weekdays()
    ->dailyAt((string) config('notifications.digest.time'))
    ->timezone((string) config('app.display_timezone', 'America/Guayaquil'))
    ->withoutOverlapping();
