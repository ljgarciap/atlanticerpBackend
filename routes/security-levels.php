<?php

use App\Modules\Auth\Http\Controllers\SecurityLevelController;
use Illuminate\Support\Facades\Route;

// SCRUM-1012 — administración de usuarios/niveles/departamentos exclusiva de Gerencia (rol base `management`) + superadmin, ADEMÁS del permiso. Un grant individual (extra_permissions/manage_users) sin ese rol ya NO alcanza.
// El listado (GET /) dejó de ser abierto a cualquier autenticado: solo lo consume la pantalla de
// Seguridad (UsersPage/RolesPage/niveles), ningún picker de otro módulo (verificado por grep en atlanticerp-frontend).
Route::middleware('permission:platform.management')->group(function () {
    Route::get('/', [SecurityLevelController::class, 'index']);
});

Route::middleware(['permission:platform.management', 'permission:security.levels'])->group(function () {
    Route::post('/',              [SecurityLevelController::class, 'store']);
    Route::put('/{id}',           [SecurityLevelController::class, 'update']);
    Route::patch('/{id}/status',  [SecurityLevelController::class, 'toggleStatus']);
    // ADR-006, Decisión 10 — matriz Crear-Editar/Aprobar por módulo de un nivel.
    Route::get('/{id}/permissions', [SecurityLevelController::class, 'permissions']);
    Route::put('/{id}/permissions', [SecurityLevelController::class, 'updatePermissions']);
});
