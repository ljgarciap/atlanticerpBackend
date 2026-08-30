<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\ForgotPasswordController;
use App\Modules\Auth\Http\Controllers\MfaController;
use App\Modules\Auth\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

// Public — no JWT required
Route::post('/login',                  [AuthController::class,          'login']);
Route::post('/refresh',                [AuthController::class,          'refresh']);
Route::post('/logout',                 [AuthController::class,          'logout']);
Route::post('/select-role',            [AuthController::class,          'selectRole']);
Route::post('/forgot-password',        [ForgotPasswordController::class, 'sendLink']);
Route::post('/reset-password',         [ForgotPasswordController::class, 'reset']);
Route::post('/mfa/verify',             [MfaController::class,           'verify']);
Route::post('/mfa/resend',             [MfaController::class,           'resend']);
Route::get('/departments',             [RegisterController::class,      'departments']);
Route::post('/register',               [RegisterController::class,      'register']);
