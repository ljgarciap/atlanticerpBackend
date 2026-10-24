<?php

declare(strict_types=1);

use App\Modules\Legacy\Http\Controllers\LegacyMigrationDashboardController;
use App\Modules\Legacy\Http\Controllers\LegacyPagosController;
use App\Modules\Legacy\Http\Controllers\LegacyVistaIcgController;
use App\Modules\Legacy\Http\Controllers\StockSyncSettingsController;
use Illuminate\Support\Facades\Route;

/**
 * SCRUM-816 — dashboard de migración ICG, prefijo /api/legacy. Gate `permission:superadmin.all` exclusivo
 * (ajuste 2026-09-04 a pedido de Luis — antes incluía `management`/Gerencia, spec cerrada
 * 2026-09-01/02; se restringe a solo equipo técnico, mismo criterio que Pagos ICG/Vista ICG abajo).
 */
Route::middleware('permission:superadmin.all')->prefix('migracion')->group(function (): void {
    Route::get('/dashboard/runs', [LegacyMigrationDashboardController::class, 'runs']);
    Route::get('/dashboard/counts', [LegacyMigrationDashboardController::class, 'counts']);
    Route::get('/dashboard/excluded', [LegacyMigrationDashboardController::class, 'excluded']);
});

/**
 * SCRUM-827 — vista de solo lectura de legacy.pagos (TESORERIA). Gate `permission:superadmin.all` exclusivo
 * (audiencia solo equipo técnico, respuesta de Luis 2026-09-02 — más restrictivo que el dashboard
 * de arriba, que sí incluye `management`).
 */
Route::middleware('permission:superadmin.all')->prefix('migracion')->group(function (): void {
    Route::get('/pagos', [LegacyPagosController::class, 'index']);
});

/**
 * "Vista ICG" (SCRUM-749, 2026-09-03) — pantalla propia bajo Migración, con una pestaña por
 * entidad, sin partir de un Subcliente puntual. Gate `permission:superadmin.all` exclusivo, mismo criterio
 * que Pagos ICG arriba. Ver docblock de LegacyVistaIcgController.
 */
Route::middleware('permission:superadmin.all')->prefix('migracion/vista')->group(function (): void {
    Route::get('/{entidad}', [LegacyVistaIcgController::class, 'index']);
    Route::get('/{entidad}/{id}', [LegacyVistaIcgController::class, 'show']);
});

/**
 * SCRUM-1114 — ajustes y estado de la sincronización de stock por delta (interruptor, umbrales del
 * circuit breaker, correos de alerta, últimas corridas con su informe de divergencia). Gate
 * `permission:superadmin.all` exclusivo, igual que el resto de Migración.
 */
Route::middleware('permission:superadmin.all')->prefix('migracion')->group(function (): void {
    Route::get('/stock-sync', [StockSyncSettingsController::class, 'show']);
    Route::put('/stock-sync', [StockSyncSettingsController::class, 'update']);
});
