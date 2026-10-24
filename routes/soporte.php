<?php

declare(strict_types=1);

use App\Modules\Soporte\Http\Controllers\SoporteWidgetController;
use Illuminate\Support\Facades\Route;

// SCRUM-923 — visible a cualquier usuario autenticado, sin gate de permiso adicional.
Route::post('/widget-code', [SoporteWidgetController::class, 'issueCode'])
    ->middleware('throttle:20,1');
