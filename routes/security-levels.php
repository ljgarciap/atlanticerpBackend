<?php

use App\Modules\Auth\Http\Controllers\SecurityLevelController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SecurityLevelController::class, 'index']);

Route::middleware('permission:security.levels')->group(function () {
    Route::post('/',              [SecurityLevelController::class, 'store']);
    Route::put('/{id}',           [SecurityLevelController::class, 'update']);
    Route::patch('/{id}/status',  [SecurityLevelController::class, 'toggleStatus']);
    // ADR-006, Decisión 10 — matriz Crear-Editar/Aprobar por módulo de un nivel.
    Route::get('/{id}/permissions', [SecurityLevelController::class, 'permissions']);
    Route::put('/{id}/permissions', [SecurityLevelController::class, 'updatePermissions']);
});
