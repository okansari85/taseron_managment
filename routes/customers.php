<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerLocationCompanyController;
use App\Http\Controllers\CustomerLocationController;
use App\Http\Controllers\CustomerOrganizationController;
use App\Http\Controllers\CustomerOrganizationRenameController;
use App\Http\Controllers\ExpertCompanyController;
use App\Http\Controllers\PeriodicEquipmentCatalogController;
use App\Http\Controllers\PkReportAnalysisTestController;
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

        Route::patch('customers/{customer}/organizations/{organization}', CustomerOrganizationRenameController::class);

        Route::get('customers/{customer}/locations',[CustomerLocationController::class, 'index']);
        Route::post('customers/{customer}/organizations/{organization}/locations', [CustomerLocationController::class, 'store']);

        Route::get('customers/{customer}/locations/{location}/companies', [CustomerLocationCompanyController::class, 'index']);
        Route::post('customers/{customer}/locations/{location}/companies', [CustomerLocationCompanyController::class, 'store']);
        Route::delete('customers/{customer}/locations/{location}/companies/{locationBusinessEntity}', [CustomerLocationCompanyController::class, 'destroy']);

        Route::get('my-companies', [ExpertCompanyController::class, 'index']);
        Route::post('my-companies', [ExpertCompanyController::class, 'store']);
        Route::patch('my-companies/{businessEntity}', [ExpertCompanyController::class, 'update']);
        Route::get('my-workplaces', [ExpertCompanyController::class, 'workplaces']);
        Route::get('nace-hazard-classes', [ExpertCompanyController::class, 'naceHazardClasses']);

        Route::get('equipment-catalog', PeriodicEquipmentCatalogController::class);

        // Test ekranı: yeni rapor algılaması (kriterler hariç) - fixture listele / göster / yeni analiz.
        Route::get('pk-report-analyses', [PkReportAnalysisTestController::class, 'index']);
        Route::get('pk-report-analyses/{fixtureId}', [PkReportAnalysisTestController::class, 'show']);
        Route::post('pk-report-analyses', [PkReportAnalysisTestController::class, 'store']);
        Route::post('pk-report-analyses/{fixtureId}/tables', [PkReportAnalysisTestController::class, 'rereadTables']);
    });
