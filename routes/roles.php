<?php

use App\Modules\Auth\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

// ADR-006, Decisión 10 — todos los autenticados (para poblar selects). El CRUD del catálogo
// (superadmin.all exclusivo) vive en routes/admin.php.
Route::get('/', [RoleController::class, 'index']);
