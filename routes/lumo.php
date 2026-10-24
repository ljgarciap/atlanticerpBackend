<?php

declare(strict_types=1);

use App\Modules\Lumo\Http\Controllers\LumoWidgetController;
use Illuminate\Support\Facades\Route;

// SCRUM-925 — defensa en profundidad: permission:lumo.view va en la ruta, no solo en el sidebar
// del frontend, para que un curl/Postman directo sin el permiso también reciba 403.
Route::post('/bitacoras-code', [LumoWidgetController::class, 'issueCode'])
    ->middleware(['throttle:20,1', 'permission:lumo.view']);
