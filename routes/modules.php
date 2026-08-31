<?php

use App\Modules\Auth\Http\Controllers\ModuleController;
use Illuminate\Support\Facades\Route;

// Catálogo de módulos de negocio, como datos — ver migración 2026_08_30_100000. Todos los
// autenticados (para poblar selects de la matriz de roles/niveles). El CRUD (superadmin.all
// exclusivo) vive en routes/admin.php, mismo patrón que routes/roles.php.
Route::get('/', [ModuleController::class, 'index']);
