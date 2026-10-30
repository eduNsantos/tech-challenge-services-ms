<?php

use App\Http\Controllers\ServiceOrderExecutionController;
use Illuminate\Support\Facades\Route;

Route::middleware('jwt')->prefix('service-orders')->group(function () {
    Route::get('/{id}', [ServiceOrderExecutionController::class, 'show']);
    Route::patch('/{id}/status', [ServiceOrderExecutionController::class, 'updateStatus']);
    Route::post('/{id}/services/{serviceId}/start', [ServiceOrderExecutionController::class, 'start']);
    Route::post('/{id}/services/{serviceId}/finish', [ServiceOrderExecutionController::class, 'finish']);
});
