<?php

use App\Shared\Catalogs\CatalogController;
use Illuminate\Support\Facades\Route;

// Fase 7a — catálogos de vocabulario por empresa, lectura para todos los autenticados (pueblan selects). La administración
// (superadmin.all exclusivo) vive en routes/admin.php.
Route::get('/domains', [CatalogController::class, 'domains']);
Route::get('/{domain}', [CatalogController::class, 'index']);
