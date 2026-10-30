<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'order'], function () {
    Route::post('/', [\App\Http\Controllers\ServiceOrderController::class, 'store']);
    Route::post('/{serviceOrderId}/services', [\App\Http\Controllers\ServiceOrderServiceController::class, 'store']);
});
