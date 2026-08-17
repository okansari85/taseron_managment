<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('login', [AuthController::class, 'login']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    Route::middleware('role:super-admin')->group(function () {
        Route::apiResource('tenants', TenantController::class);
    });
});


Route::post('test-json', function (Request $request) {
    return response()->json([
        'all' => $request->all(),
        'content' => $request->getContent(),
        'is_json' => $request->isJson(),
    ]);
});
