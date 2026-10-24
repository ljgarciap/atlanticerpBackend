<?php

use App\Modules\Auth\Http\Controllers\DepartmentController;
use Illuminate\Support\Facades\Route;

// Hallazgo Arquitecto (2026-08-31, análisis SCRUM-625/627/631): estas 4 rutas no tenían NINGÚN
// gate de permiso/rol en el backend — solo el middleware global `api` (JWT válido). El control
// "solo superadmin/management" solo existía en el frontend (RequireRole en App.tsx), así que
// cualquier usuario autenticado de cualquier rol podía crear/editar/desactivar departamentos
// llamando la API directo. Mismo permiso que /api/users (security.users) — Departamentos es
// parte de la misma pantalla de administración (UserFormDrawer.tsx lee este catálogo).
// SCRUM-1012 — además exige rol `management`/superadmin (un grant individual de security.users ya no alcanza).
Route::middleware(['role:superadmin,management', 'permission:security.users'])->group(function () {
    Route::get('/',              [DepartmentController::class, 'index']);
    Route::post('/',             [DepartmentController::class, 'store']);
    Route::put('/{id}',          [DepartmentController::class, 'update']);
    Route::patch('/{id}/status', [DepartmentController::class, 'toggleStatus']);
});
