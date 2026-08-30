<?php

use App\Modules\AI\Http\Controllers\AIController;
use Illuminate\Support\Facades\Route;

Route::post('/summarize',        [AIController::class, 'summarize'])->middleware(['permission:ai.use', 'throttle:5,1']);
Route::post('/suggest-actions',  [AIController::class, 'suggestActions'])->middleware(['permission:ai.use', 'throttle:5,1']);
Route::post('/draft-message',    [AIController::class, 'draftMessage'])->middleware(['permission:ai.use', 'throttle:5,1']);
Route::post('/monthly-analysis', [AIController::class, 'monthlyAnalysis'])->middleware(['permission:ai.use', 'throttle:5,1']);
Route::get('/analyses/{id}',     [AIController::class, 'show'])->middleware('permission:ai.use');
