<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/',                 [NotificationController::class, 'index']);
Route::get('/unread-count',     [NotificationController::class, 'unreadCount']);
Route::post('/read-all',        [NotificationController::class, 'readAll']);
Route::post('/{id}/read',       [NotificationController::class, 'read']);
