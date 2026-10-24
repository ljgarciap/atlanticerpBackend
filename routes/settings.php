<?php

use App\Modules\Auth\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/preferences',  [SettingsController::class, 'getPreferences']);
Route::patch('/preferences', [SettingsController::class, 'updatePreferences']);

Route::middleware('permission:settings.global')->group(function () {
    Route::get('/system',   [SettingsController::class, 'getSystemSettings']);
    Route::patch('/system', [SettingsController::class, 'updateSystemSettings']);
});

// Read-only: accessible to all authenticated users (needed by DocumentSection for all roles)
Route::get('/documents/max-size', [SettingsController::class, 'getDocumentsMaxSize']);

// Read-only: accessible to all authenticated users (needed by TopBar's personal Demo Mode toggle
// to know whether the global gate is on — see docs/specs/demo-mode.md)
Route::get('/demo-mode-status', [SettingsController::class, 'getDemoModeStatus']);

Route::middleware('permission:superadmin.all')->group(function () {
    Route::get('/ai-key',             [SettingsController::class, 'getAiKey']);
    Route::put('/ai-key',             [SettingsController::class, 'updateAiKey']);
    Route::put('/documents/max-size', [SettingsController::class, 'updateDocumentsMaxSize']);
});
