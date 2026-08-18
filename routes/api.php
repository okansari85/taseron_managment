<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\LocationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('login', [AuthController::class, 'login']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    Route::middleware('role:super-admin')->group(function () {
        Route::apiResource('tenants', TenantController::class);
        Route::apiResource('organizations', OrganizationController::class);
        Route::apiResource('companies', CompanyController::class);
        Route::apiResource('locations', LocationController::class);
    });
});


