<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,10')
        ->name('auth.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])
            ->name('auth.logout');

        Route::get('dashboard', [DashboardController::class, 'show'])
            ->name('dashboard.show');

        Route::post('dashboard/refresh', [DashboardController::class, 'refresh'])
            ->middleware('throttle:6,1')
            ->name('dashboard.refresh');
    });
});
