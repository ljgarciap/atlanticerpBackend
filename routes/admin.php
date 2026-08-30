<?php

use App\Modules\AI\Http\Controllers\AiModelPricingController;
use App\Modules\AI\Http\Controllers\AiUsageController;
use App\Modules\Auth\Http\Controllers\AuditLogController;
use App\Modules\Auth\Http\Controllers\DemoCleanupController;
use App\Modules\Auth\Http\Controllers\RoleController;
use App\Modules\Auth\Http\Controllers\SecurityAnomalyController;
use App\Modules\Auth\Http\Controllers\UserController;
use App\Modules\CRM\Http\Controllers\CrmExportController;
use App\Modules\CRM\Http\Controllers\NotificationRuleController;
use App\Modules\VentasDiseno\Http\Controllers\CatalogUatTestDataCleanupController;
use Illuminate\Support\Facades\Route;

// Superadmin-only operations
Route::middleware('permission:superadmin.all')->group(function (): void {
    Route::post('/demo/cleanup',   DemoCleanupController::class);
    Route::get('/export/crm',      CrmExportController::class);

    // SCRUM-745 — borra la data de prueba UAT del Catálogo (reference UAT-%) una vez
    // terminado el UAT. Ver CatalogUatTestDataCleanupController.
    Route::post('/catalog/uat-test-data/cleanup', CatalogUatTestDataCleanupController::class);

    // Auditoría de consumo de IA (pedido de Luis 2026-07-20, ver AiUsageController).
    Route::get('/ai/usage', [AiUsageController::class, 'index']);
    Route::get('/ai/pricing',          [AiModelPricingController::class, 'index']);
    Route::put('/ai/pricing/{model}',  [AiModelPricingController::class, 'update']);

    // SCRUM-792 (Epic SCRUM-788 — Logs y Telemetría) — visor generalizado de audit_logs, todos
    // los módulos. Mismo permiso/sección que /ai/usage arriba, ver docblock de AuditLogController.
    Route::get('/audit/logs', [AuditLogController::class, 'index']);

    // Epic 11 — Seguridad Agéntica. El job solo sugiere; /confirm es la única vía de bloqueo real.
    Route::get('/security/anomalies',                 [SecurityAnomalyController::class, 'index']);
    Route::post('/security/anomalies/{id}/confirm',   [SecurityAnomalyController::class, 'confirm']);
    Route::post('/security/anomalies/{id}/dismiss',   [SecurityAnomalyController::class, 'dismiss']);
    Route::post('/security/users/{userId}/unblock',   [SecurityAnomalyController::class, 'unblock']);
    Route::post('/security/users/{userId}/mfa/reset', [SecurityAnomalyController::class, 'resetMfa']);

    // SCRUM-49 Fase 2 — motor de reglas de notificaciones (whitelist de modelos, ver
    // App\Modules\CRM\Notifications\NotifiableModel).
    Route::get('/notification-rules',           [NotificationRuleController::class, 'index']);
    Route::post('/notification-rules',          [NotificationRuleController::class, 'store']);
    Route::put('/notification-rules/{id}',      [NotificationRuleController::class, 'update']);
    Route::delete('/notification-rules/{id}',   [NotificationRuleController::class, 'destroy']);

    // ADR-006, Decisión 10 — administración del catálogo de roles, exclusiva de superadmin
    // (cerrado 2026-07-11, pregunta técnica (b)2: NO comparte permission:security.levels).
    Route::post('/roles',                      [RoleController::class, 'store']);
    Route::put('/roles/{id}',                  [RoleController::class, 'update']);
    Route::delete('/roles/{id}',               [RoleController::class, 'destroy']);
    Route::get('/roles/{id}/visibility',       [RoleController::class, 'visibility']);
    Route::put('/roles/{id}/visibility',       [RoleController::class, 'updateVisibility']);

    // SCRUM-724 — override de visibilidad de módulos/menús por usuario, por encima
    // de la visibilidad por rol de arriba. Mismo permiso que la administración de
    // roles (superadmin.all), no security.users — es una superficie de permisos
    // nueva, no una operación de administración de usuarios "normal".
    Route::get('/users/{id}/module-visibility', [UserController::class, 'moduleVisibility'])->whereNumber('id');
    Route::put('/users/{id}/module-visibility', [UserController::class, 'updateModuleVisibility'])->whereNumber('id');

    // SCRUM-739 (UAT-2) — máscara global de rollout (independiente del override individual de
    // arriba, ver UatVisibilityService), aplica a todos los usuarios no-superadmin a la vez.
    Route::get('/module-visibility/bulk',  [UserController::class, 'bulkModuleVisibilityState']);
    Route::post('/module-visibility/bulk', [UserController::class, 'bulkModuleVisibility']);
});

// ADR-006, Decisión 10 — flags transversales por persona, mismo permiso que el resto de
// administración de usuarios (security.users), no superadmin.all.
Route::middleware('permission:security.users')->group(function (): void {
    Route::patch('/users/{id}/flags', [UserController::class, 'updateFlags'])->whereNumber('id');
});
