<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ai:reset-stale')->everyFiveMinutes();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Epic 11 — Seguridad Agéntica. Horario de baja actividad, no coincide con los jobs de arriba.
Schedule::command('security:analyze')->dailyAt('03:00');

// SCRUM-49 — Notificaciones. Horario de baja actividad, distinto de security:analyze.
Schedule::command('notifications:archive-read')->dailyAt('03:15');
Schedule::command('notifications:dispatch-scheduled')->everyFiveMinutes();
