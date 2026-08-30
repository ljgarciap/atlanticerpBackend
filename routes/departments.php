<?php

use App\Modules\Auth\Http\Controllers\DepartmentController;
use Illuminate\Support\Facades\Route;

Route::get('/',              [DepartmentController::class, 'index']);
Route::post('/',             [DepartmentController::class, 'store']);
Route::put('/{id}',          [DepartmentController::class, 'update']);
Route::patch('/{id}/status', [DepartmentController::class, 'toggleStatus']);
