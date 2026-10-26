<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'order'], function () {
    Route::post('/', [\App\Http\Controllers\ServiceOrderController::class, 'store']);
});
