<?php

use App\Modules\Auth\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/catalog', [UserController::class, 'catalog']);

Route::middleware('permission:security.users')->group(function () {
    Route::get('/',              [UserController::class, 'index']);
    Route::get('/{id}',          [UserController::class, 'show'])->whereNumber('id');
    Route::post('/',              [UserController::class, 'store']);
    Route::put('/{id}',           [UserController::class, 'update'])->whereNumber('id');
    Route::patch('/{id}/status',  [UserController::class, 'toggleStatus'])->whereNumber('id');
    Route::get('/pending',        [UserController::class, 'pending']);
    Route::post('/{id}/approve',  [UserController::class, 'approve'])->whereNumber('id');
    Route::delete('/{id}/reject', [UserController::class, 'reject'])->whereNumber('id');
});
