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

// SCRUM-998 — borra los ZIP de catálogo en PDF vencidos (retención configurable) y cierra exportaciones colgadas.
Schedule::command('catalog-pdf:purge-exports')->dailyAt('04:00')->withoutOverlapping();

// SCRUM-812 (REQ-636, Epic SCRUM-788) — retención 12 meses de audit_logs, archiva a S3 antes de
// purgar. Horario de baja actividad, distinto de los jobs de arriba.
Schedule::command('audit-logs:archive')->dailyAt('03:30');

// SCRUM-879 (decisión E-1) — refleja las comisiones internas de meses ya cerrados como salida de
// caja. Diario con guard de fecha interno (no se pierde el mes si el server estuvo caído el día 1).
Schedule::command('commissions:settle-internal')->dailyAt('03:45');

// SCRUM-1168 F1 — modo sombra del libro de movimientos de cobros: cada noche compara el libro con `saldo_aplicado` de todas las facturas y con
// el saldo de los abonos. Sale con error (y deja una línea en el log) si hay diferencias; las lecturas solo pasan al libro con cero diferencias.
Schedule::command('cobros:verificar-libro')->dailyAt('03:50');

// Sincronización automática de ICG DETENIDA (2026-10-14, decisión de Luis): ICG ya no se usa y AtlanticERP es la
// fuente de verdad. La cadena `legacy:sync-incremental-all` (SCRUM-838; prod diario 21:00, qa domingo 01:00,
// dev domingo 05:00) reescribía nombre, marca, precio y costo de los productos que creó el pipeline, y
// `legacy:check-sync-health` (SCRUM-839) alertaría por falta de corridas, por eso salen las dos entradas.
// Los comandos `legacy:*` siguen disponibles para correrlos a mano. Para reactivarla, restaurar las entradas
// desde el historial de git de este archivo (commit anterior a este cambio).

// SCRUM-971 (épica SCRUM-969 — integración Lafayette Lighting, Bodega Zona Libre). Cadencia por
// ambiente, ajustada 2026-09-27 a pedido de Luis — mismo criterio que legacy:sync-incremental-all
// (SCRUM-838): producción necesita el dato fresco 2x/día, dev/test alcanza con 1x/semana (en días
// distintos para no coincidir). Sin `->timezone()` explícito, igual que el resto de este archivo:
// config('app.timezone') ya es America/Panama (SCRUM-910). `withoutOverlapping()` protege contra
// una corrida que se solape consigo misma — el proveedor limita a 3-5 conexiones simultáneas.
Schedule::command('lafayette:sync-productos')
    ->dailyAt('12:00')
    ->environments(['production'])
    ->withoutOverlapping();
Schedule::command('lafayette:sync-productos')
    ->dailyAt('22:00')
    ->environments(['production'])
    ->withoutOverlapping();
Schedule::command('lafayette:sync-productos')
    ->weeklyOn(6, '22:00') // sábado
    ->environments(['dev'])
    ->withoutOverlapping();
Schedule::command('lafayette:sync-productos')
    ->weeklyOn(0, '22:00') // domingo
    ->environments(['qa']) // "qa" = test.atlanticerp.ai, ver ../../CLAUDE.md tabla de entornos
    ->withoutOverlapping();
