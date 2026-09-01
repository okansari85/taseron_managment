<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ContractorController;
use App\Http\Controllers\LocationBusinessEntityController;
use App\Http\Controllers\OrganizationCompanyController;
use App\Http\Controllers\OrganizationContractorController;
use App\Http\Controllers\TenantOnboardingController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BrandLocationController;
use App\Http\Controllers\OperationalRegionController;
use App\Http\Controllers\OrganizationLocationController;
use App\Models\City;
use App\Models\District;

Route::get('/user', function (Request $request) { return $request->user(); })->middleware('auth:sanctum');
Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::middleware('role:super-admin')->group(function () {
        Route::apiResource('tenants', TenantController::class);
        Route::post('tenant-onboarding', [TenantOnboardingController::class, 'store']);
    });
    Route::middleware('tenant')->group(function () {
        Route::middleware('role:super-admin')->group(function () {
            Route::get('cities', fn () => response()->json(City::query()->orderBy('name')->get(['id','name'])));
            Route::get('cities/{city}/districts', fn (City $city) => response()->json($city->districts()->orderBy('name')->get(['id','city_id','name'])));
            Route::apiResource('organizations', OrganizationController::class);
            Route::apiResource('companies', CompanyController::class);
            Route::apiResource('contractors', ContractorController::class);
            Route::apiResource('locations', LocationController::class);
            Route::apiResource('brands', BrandController::class);
            Route::apiResource('locations.operational-regions', OperationalRegionController::class);

            Route::get('organization-companies', [OrganizationCompanyController::class, 'indexForTenant']);
            Route::get('organizations/{organization}/companies', [OrganizationCompanyController::class, 'index']);
            Route::put('organizations/{organization}/companies', [OrganizationCompanyController::class, 'sync']);
            Route::post('organizations/{organization}/companies/{company}', [OrganizationCompanyController::class, 'attach']);
            Route::delete('organizations/{organization}/companies/{company}', [OrganizationCompanyController::class, 'detach']);

            Route::get('organization-contractors', [OrganizationContractorController::class, 'contractorsForTenant']);
            Route::get('organizations/{organization}/contractors', [OrganizationContractorController::class, 'index']);
            Route::post('organizations/{organization}/contractors/{contractor}', [OrganizationContractorController::class, 'attach']);
            Route::delete('organizations/{organization}/contractors/{contractor}', [OrganizationContractorController::class, 'detach']);

            Route::get('organizations/{organization}/locations', [OrganizationLocationController::class, 'index']);
            Route::put('organizations/{organization}/locations', [OrganizationLocationController::class, 'sync']);
            Route::post('organizations/{organization}/locations', [OrganizationLocationController::class, 'store']);
            Route::post('organizations/{organization}/locations/{location}', [OrganizationLocationController::class, 'attach']);
            Route::delete('organizations/{organization}/locations/{location}', [OrganizationLocationController::class, 'detach']);

            Route::get('brands/{brand}/locations', [BrandLocationController::class, 'index']);
            Route::put('brands/{brand}/locations', [BrandLocationController::class, 'sync']);
            Route::post('brands/{brand}/locations/{location}', [BrandLocationController::class, 'attach']);
            Route::delete('brands/{brand}/locations/{location}', [BrandLocationController::class, 'detach']);

            Route::get('locations/{location}/business-entities', [LocationBusinessEntityController::class, 'index']);
            Route::post('locations/{location}/business-entities', [LocationBusinessEntityController::class, 'store']);
            Route::put('locations/{location}/business-entities/{businessEntity}', [LocationBusinessEntityController::class, 'update']);
            Route::delete('locations/{location}/business-entities/{businessEntity}', [LocationBusinessEntityController::class, 'destroy']);
        });
    });
});
