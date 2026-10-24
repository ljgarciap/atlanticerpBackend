<?php

use App\Modules\Auth\Http\Controllers\AdminPasswordResetController;
use App\Modules\Auth\Http\Controllers\ImpersonationController;
use App\Modules\Auth\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// SCRUM-1012 — administración de usuarios/niveles/departamentos exclusiva de Gerencia (rol base `management`) + superadmin, ADEMÁS del permiso. Un grant individual (extra_permissions/manage_users) sin ese rol ya NO alcanza.
Route::middleware('role:superadmin,management')->group(function () {
    Route::get('/catalog', [UserController::class, 'catalog']);
});

// Reset administrativo de contraseñas (docs/runbooks/reset-password.md) — SOLO superadmin, con límite de peticiones POR USUARIO
// (limitador `password-reset-admin`, config/throttle.php) y re-autenticación del operador dentro del controlador (H3).
// Rutas estáticas/específicas antes que cualquier `/{id}` genérico.
Route::middleware(['role:superadmin', 'throttle:password-reset-admin'])->group(function () {
    Route::post('/reset-passwords',      [AdminPasswordResetController::class, 'resetMany']);
    Route::post('/{id}/reset-password',  [AdminPasswordResetController::class, 'reset'])->whereNumber('id');
});

// SCRUM-1158 — «Ver como usuario» (solo lectura, ADR-010). `permission:superadmin.all` deja rastro `security.access_denied` si lo intenta otra
// persona; el rol de superadmin se revalida además en BD dentro del servicio. Límite de inicios por usuario (limitador `impersonation-start`).
Route::middleware(['permission:superadmin.all', 'throttle:impersonation-start'])->group(function () {
    Route::post('/{id}/impersonate', [ImpersonationController::class, 'start'])->whereNumber('id');
});

Route::middleware(['role:superadmin,management', 'permission:security.users'])->group(function () {
    Route::get('/',              [UserController::class, 'index']);
    Route::get('/{id}',          [UserController::class, 'show'])->whereNumber('id');
    Route::post('/',              [UserController::class, 'store']);
    Route::put('/{id}',           [UserController::class, 'update'])->whereNumber('id');
    Route::patch('/{id}/status',  [UserController::class, 'toggleStatus'])->whereNumber('id');
    Route::get('/pending',        [UserController::class, 'pending']);
    Route::post('/{id}/approve',  [UserController::class, 'approve'])->whereNumber('id');
    Route::delete('/{id}/reject', [UserController::class, 'reject'])->whereNumber('id');
});
