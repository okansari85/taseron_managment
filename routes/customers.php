<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerLocationController;
use App\Http\Controllers\CustomerOrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['auth:sanctum', 'tenant', 'workspace-context', 'web-role:super-admin,tenant,isg', \Illuminate\Routing\Middleware\SubstituteBindings::class])
    ->group(function () {
        Route::apiResource('customers', CustomerController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::get('customers/{customer}/organizations', [CustomerOrganizationController::class, 'tree']);
        Route::post('customers/{customer}/organizations', [CustomerOrganizationController::class, 'store']);
        Route::post('customers/{customer}/organizations/{organization}', [CustomerOrganizationController::class, 'attach']);
        Route::delete('customers/{customer}/organizations/{organization}', [CustomerOrganizationController::class, 'detach']);

        Route::get('customers/{customer}/locations', [CustomerLocationController::class, 'index']);
        Route::post('customers/{customer}/organizations/{organization}/locations', [CustomerLocationController::class, 'store']);
    });
