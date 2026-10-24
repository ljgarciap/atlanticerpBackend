<?php

use App\Modules\AI\Http\Controllers\AiModelPricingController;
use App\Modules\AI\Http\Controllers\AiUsageController;
use App\Modules\Auth\Http\Controllers\AuditActivityController;
use App\Modules\Auth\Http\Controllers\AuditLogController;
use App\Modules\Auth\Http\Controllers\GlobalSettingsController;
use App\Modules\Auth\Http\Controllers\ImpersonationAdminController;
use App\Modules\Auth\Http\Controllers\ModuleController;
use App\Modules\Auth\Http\Controllers\RoleController;
use App\Modules\Auth\Http\Controllers\SecurityAnomalyController;
use App\Modules\Auth\Http\Controllers\UserController;
use App\Modules\CRM\Http\Controllers\CrmExportController;
use App\Modules\CRM\Http\Controllers\NotificationRuleController;
use App\Modules\VentasDiseno\Http\Controllers\CatalogUatTestDataCleanupController;
use Illuminate\Support\Facades\Route;

// Superadmin-only operations
Route::middleware('permission:superadmin.all')->group(function (): void {
    Route::get('/export/crm',      CrmExportController::class);

    // SCRUM-745 — borra la data de prueba UAT del Catálogo (reference UAT-%) una vez
    // terminado el UAT. Ver CatalogUatTestDataCleanupController.
    Route::post('/catalog/uat-test-data/cleanup', CatalogUatTestDataCleanupController::class);

    // SCRUM-944/945 (Pre-QA hallazgo E) — claves de GlobalSetting en lista blanca, ver
    // GlobalSettingsRegistry (nunca anthropic_api_key ni claves fuera de la lista).
    Route::get('/global-settings',         [GlobalSettingsController::class, 'index']);
    Route::put('/global-settings/{key}',   [GlobalSettingsController::class, 'update']);

    // Auditoría de consumo de IA (pedido de Luis 2026-07-20, ver AiUsageController).
    Route::get('/ai/usage', [AiUsageController::class, 'index']);
    Route::get('/ai/pricing',          [AiModelPricingController::class, 'index']);
    Route::put('/ai/pricing/{model}',  [AiModelPricingController::class, 'update']);

    // SCRUM-792 (Epic SCRUM-788 — Logs y Telemetría) — visor generalizado de audit_logs, todos
    // los módulos. Mismo permiso/sección que /ai/usage arriba, ver docblock de AuditLogController.
    Route::get('/audit/logs', [AuditLogController::class, 'index']);
    // SCRUM-813 — catálogo de entity_type para el <select> del filtro (ver docblock del método).
    Route::get('/audit/logs/entity-types', [AuditLogController::class, 'entityTypes']);
    // SCRUM-1020 — dashboard de actividad (distribución de interacciones por usuario/tipo/módulo/día).
    Route::get('/audit/activity-summary', [AuditActivityController::class, 'summary']);

    // Epic 11 — Seguridad Agéntica. El job solo sugiere; /confirm es la única vía de bloqueo real.
    Route::get('/security/anomalies',                 [SecurityAnomalyController::class, 'index']);
    Route::post('/security/anomalies/{id}/confirm',   [SecurityAnomalyController::class, 'confirm']);
    Route::post('/security/anomalies/{id}/dismiss',   [SecurityAnomalyController::class, 'dismiss']);
    Route::post('/security/users/{userId}/unblock',   [SecurityAnomalyController::class, 'unblock']);
    Route::post('/security/users/{userId}/mfa/reset', [SecurityAnomalyController::class, 'resetMfa']);

    // SCRUM-1158 — sesiones de «Ver como usuario»: listado para la pantalla «Sesiones de soporte» y terminación forzada de una sesión activa.
    Route::get('/security/impersonations',                  [ImpersonationAdminController::class, 'index']);
    Route::post('/security/impersonations/{id}/revoke',     [ImpersonationAdminController::class, 'revoke']);

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

    // Catálogo de módulos de negocio, como datos (hallazgo de la auditoría del esquema de
    // permisos, Atlantic/CLAUDE.md sesión 2026-08-30) — mismo permiso que el catálogo de roles,
    // mismo criterio: cambia el alcance de todos los roles/niveles a la vez.
    Route::post('/modules',        [ModuleController::class, 'store']);
    Route::put('/modules/{id}',    [ModuleController::class, 'update']);
    Route::delete('/modules/{id}', [ModuleController::class, 'destroy']);

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
// SCRUM-1012 — también exige rol management/superadmin.
Route::middleware(['permission:platform.management', 'permission:security.users'])->group(function (): void {
    Route::patch('/users/{id}/flags', [UserController::class, 'updateFlags'])->whereNumber('id');
});
