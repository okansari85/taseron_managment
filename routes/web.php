<?php

use App\Http\Controllers\Api\ExpertController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/experts/set-password', [ExpertController::class, 'setPassword']);

Route::middleware(['auth:sanctum', 'web-role:super-admin'])->group(function () {
    Route::post('/api/experts', [ExpertController::class, 'store']);
});
