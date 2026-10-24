<?php

declare(strict_types=1);

use App\Modules\Biblioteca\Http\Controllers\BibliotecaController;
use App\Modules\Biblioteca\Http\Middleware\EnsureLibraryConfigured;
use Illuminate\Support\Facades\Route;

// SCRUM-984 — lectura: cualquier usuario autenticado (como Soporte, sin gate de permiso adicional);
// escritura: `manuales.manage` en la ruta (defensa en profundidad, no solo en el sidebar).
// Sin AWS_LIBRARY_BUCKET todo responde 503 (EnsureLibraryConfigured).
Route::middleware(EnsureLibraryConfigured::class)->group(function (): void {
    Route::get('/', [BibliotecaController::class, 'index'])->middleware('throttle:biblioteca-lectura');
    Route::get('/config', [BibliotecaController::class, 'config'])->middleware('permission:manuales.manage');
    Route::put('/config', [BibliotecaController::class, 'updateConfig'])->middleware('permission:superadmin.all');

    Route::post('/uploads', [BibliotecaController::class, 'initUpload'])
        ->middleware(['throttle:biblioteca-escritura', 'permission:manuales.manage']);
    Route::post('/uploads/{uploadId}/finalize', [BibliotecaController::class, 'finalizeUpload'])
        ->middleware(['throttle:biblioteca-escritura', 'permission:manuales.manage']);

    Route::get('/{id}/url', [BibliotecaController::class, 'url'])->middleware('throttle:biblioteca-lectura');
    Route::delete('/{id}', [BibliotecaController::class, 'destroy'])
        ->middleware(['throttle:biblioteca-escritura', 'permission:manuales.manage']);
});
