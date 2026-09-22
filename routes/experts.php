<?php

use App\Http\Controllers\Api\ExpertController;
use Illuminate\Support\Facades\Route;

Route::post('experts/set-password', [ExpertController::class, 'setPassword']);

Route::middleware(['auth:sanctum', 'web-role:super-admin'])->group(function () {
    Route::post('experts', [ExpertController::class, 'store']);
    Route::post('experts/{tenant}/impersonate', [ExpertController::class, 'impersonate']);
});
