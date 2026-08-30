<?php

declare(strict_types=1);

use App\Modules\Gerencia\Http\Controllers\GerenciaAprobacionController;
use App\Modules\Gerencia\Http\Controllers\GerenciaHomeController;
use App\Modules\Gerencia\Http\Controllers\ReglaAprobacionController;
use App\Modules\Integrations\Outlook\Http\Controllers\OutlookCalendarController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo Gerencia — SCRUM-156 a SCRUM-172
|--------------------------------------------------------------------------
*/

Route::get('/home', [GerenciaHomeController::class, 'home'])
    ->middleware('permission:gerencia.view');

Route::get('/vendors', [GerenciaHomeController::class, 'vendors'])
    ->middleware('permission:gerencia.view');

Route::get('/clients', [GerenciaHomeController::class, 'clients'])
    ->middleware('permission:gerencia.view');

// REQ-102 (SCRUM-164) — mismo endpoint compartido con Ventas & Diseño/Compras/Bodega/Servicios
// (`OutlookCalendarController`). Gerencia queda deliberadamente FUERA de
// `MODULES_WITH_TEAM_CALENDAR` (mismo criterio que Servicios) — RN1/PERMISOS de este ticket son
// "nadie ve el calendario de otro desde acá", el frontend nunca manda scope=team/owner_id.
Route::prefix('calendar')->group(function (): void {
    Route::get('/', [OutlookCalendarController::class, 'index'])
        ->defaults('module', 'gerencia')
        ->middleware('permission:gerencia.view');
});

// SCRUM-162 — Aprobar / Rechazar solicitudes desde el panel de Gerencia.
Route::prefix('aprobaciones')->middleware('permission:gerencia.view')->group(function (): void {
    Route::post('/{type}/{id}/aprobar',  [GerenciaAprobacionController::class, 'aprobar']);
    Route::post('/{type}/{id}/rechazar', [GerenciaAprobacionController::class, 'rechazar']);
});

// REQ-101 (SCRUM-163) — infraestructura configurable de REGLA_APROBACION (tipo de solicitud →
// aprobador(es)). "Quién puede configurar esto" está pendiente de definir con el cliente (ver
// Excel 6__Requerimientos_Gerencia.xlsx) — hasta entonces, exclusivo de superadmin, nadie de
// Mark/David/Whil individualmente lo ve todavía.
Route::prefix('reglas-aprobacion')->group(function (): void {
    Route::middleware('role:superadmin')->group(function (): void {
        Route::get('/', [ReglaAprobacionController::class, 'index']);
        Route::post('/', [ReglaAprobacionController::class, 'store']);
        Route::put('/{id}', [ReglaAprobacionController::class, 'update']);
        Route::delete('/{id}', [ReglaAprobacionController::class, 'destroy']);
    });
});
