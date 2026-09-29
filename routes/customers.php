<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerLocationCompanyController;
use App\Http\Controllers\CustomerLocationController;
use App\Http\Controllers\CustomerOrganizationController;
use App\Http\Controllers\CustomerOrganizationRenameController;
use App\Http\Controllers\CustomerPurgeController;
use App\Http\Controllers\ExpertCompanyController;
use App\Http\Controllers\PeriodicEquipmentCatalogController;
use App\Http\Controllers\PkBulkReportController;
use App\Http\Controllers\PkDashboardController;
use App\Http\Controllers\PkEquipmentController;
use App\Http\Controllers\PkInstallationController;
use App\Http\Controllers\PkInstallationSystemRequestController;
use App\Http\Controllers\PkReportAnalysisHistoryController;
use App\Http\Controllers\PkReportAnalysisTestController;
use App\Http\Controllers\PeriodicEquipmentSpecRequestController;
use App\Http\Controllers\PkAiSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['auth:sanctum', 'tenant', 'workspace-context', 'web-role:super-admin,tenant,isg', \Illuminate\Routing\Middleware\SubstituteBindings::class])
    ->group(function () {
        Route::apiResource('customers', CustomerController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        // pktakip: müşteriyi altındaki her şeyle (organizasyon, lokasyon, işyeri, ekipman, kontrol kaydı) birlikte silme.
        Route::get('customers/{customer}/purge-summary', [CustomerPurgeController::class, 'summary']);
        Route::delete('customers/{customer}/purge', [CustomerPurgeController::class, 'destroy']);

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
        // İşyeri ekleme: seçilen müşteride gösterilecek firmalar (başka müşterilerin firmaları gizlenir).
        Route::get('customers/{customer}/companies', [ExpertCompanyController::class, 'customerCompanies']);
        Route::get('customer-company-counts', [ExpertCompanyController::class, 'customerCompanyCounts']);
        Route::get('nace-hazard-classes', [ExpertCompanyController::class, 'naceHazardClasses']);

        Route::get('equipment-catalog', PeriodicEquipmentCatalogController::class);

        // Ekipmanlar ekranı: periyodik kontrole tabi ekipmanlar ve kontrol kayıtları.
        // pktakip tesisatları (şimdilik Yangın Tesisatı): ekipmanlardan ayrı.
        Route::get('pk-installation-catalog', [PkInstallationController::class, 'catalog']);
        Route::get('pk-installations', [PkInstallationController::class, 'index']);
        Route::post('pk-installations', [PkInstallationController::class, 'store']);
        Route::post('pk-installations/report-analysis', [PkInstallationController::class, 'analyzeReport']);
        Route::post('pk-installations/reports', [PkInstallationController::class, 'storeReport']);
        Route::get('pk-installations/{pkInstallation}', [PkInstallationController::class, 'show']);
        Route::patch('pk-installations/{pkInstallation}', [PkInstallationController::class, 'update']);
        Route::delete('pk-installations/{pkInstallation}', [PkInstallationController::class, 'destroy']);
        Route::post('pk-installations/{pkInstallation}/pending', [PkInstallationController::class, 'storePending']);
        // Analiz geçmişi (Ayarlar): yapay zeka ile yapılan her rapor okuması.
        Route::get('pk-analysis-history', [PkReportAnalysisHistoryController::class, 'index']);
        Route::get('pk-analysis-history/{analysis}', [PkReportAnalysisHistoryController::class, 'show']);
        Route::get('pk-analysis-history/{analysis}/file', [PkReportAnalysisHistoryController::class, 'file']);
        Route::get('pk-installations/{pkInstallation}/reports/{pkInstallationReport}/file', [PkInstallationController::class, 'downloadReport']);
        Route::delete('pk-installations/{pkInstallation}/reports/{pkInstallationReport}', [PkInstallationController::class, 'destroyReport']);
        Route::get('pk-equipment', [PkEquipmentController::class, 'index']);
        Route::post('pk-equipment', [PkEquipmentController::class, 'store']);
        Route::post('pk-equipment/bulk-delete', [PkEquipmentController::class, 'bulkDestroy']);
        Route::post('pk-equipment/bulk-deactivate', [PkEquipmentController::class, 'bulkDeactivate']);
        Route::post('pk-equipment/bulk-activate', [PkEquipmentController::class, 'bulkActivate']);
        // Genel bakış ve durum raporu.
        Route::get('pk-dashboard', [PkDashboardController::class, 'overview']);
        Route::get('pk-dashboard/report', [PkDashboardController::class, 'report']);
        Route::get('pk-dashboard/due', [PkDashboardController::class, 'due']);
        // Tüp kontrol formları (toplu rapor).
        Route::get('pk-bulk-reports', [PkBulkReportController::class, 'index']);
        Route::post('pk-bulk-reports', [PkBulkReportController::class, 'store']);
        Route::get('pk-bulk-reports/{pkBulkReport}', [PkBulkReportController::class, 'show']);
        Route::get('pk-bulk-reports/{pkBulkReport}/file', [PkBulkReportController::class, 'file']);
        Route::delete('pk-bulk-reports/{pkBulkReport}', [PkBulkReportController::class, 'destroy']);
        Route::post('pk-equipment/report-analysis', [PkEquipmentController::class, 'analyzeNewReport']);
        Route::post('pk-equipment/report-analysis/{analysisId}/draft', [PkEquipmentController::class, 'previewDraft']);
        Route::post('pk-equipment/from-report', [PkEquipmentController::class, 'storeFromReport']);
        Route::get('pk-equipment/{pkEquipment}', [PkEquipmentController::class, 'show']);
        Route::patch('pk-equipment/{pkEquipment}', [PkEquipmentController::class, 'update']);
        Route::delete('pk-equipment/{pkEquipment}', [PkEquipmentController::class, 'destroy']);
        Route::post('pk-equipment/{pkEquipment}/inspections', [PkEquipmentController::class, 'storeInspection']);
        Route::get('pk-equipment/{pkEquipment}/inspections/{pkInspection}/report', [PkEquipmentController::class, 'downloadReport']);
        Route::patch('pk-equipment/{pkEquipment}/inspections/{pkInspection}', [PkEquipmentController::class, 'updateInspection']);
        Route::delete('pk-equipment/{pkEquipment}/inspections/{pkInspection}', [PkEquipmentController::class, 'destroyInspection']);
        Route::post('pk-equipment/{pkEquipment}/inspections/{pkInspection}/spec-requests', [PkEquipmentController::class, 'requestSpec']);
        Route::post('pk-equipment/{pkEquipment}/report-analysis', [PkEquipmentController::class, 'analyzeReport']);
        Route::post('pk-equipment/{pkEquipment}/report-analysis/{analysisId}', [PkEquipmentController::class, 'rebindAnalysis']);
        Route::post('pk-equipment/{pkEquipment}/properties', [PkEquipmentController::class, 'correctProperty']);
        Route::delete('pk-equipment/{pkEquipment}/properties/{key}', [PkEquipmentController::class, 'removeProperty']);
        // Ayarlar → Yapay zeka: rapor okuyan istemci, modeller, API anahtarları (şimdilik tüm uzmanlar; ileride süper admin).
        Route::get('pk-ai-settings', [PkAiSettingsController::class, 'show']);
        Route::patch('pk-ai-settings', [PkAiSettingsController::class, 'update']);
        Route::get('pk-catalog-requests', [PeriodicEquipmentSpecRequestController::class, 'index']);
        Route::post('pk-catalog-requests/{specRequest}/approve', [PeriodicEquipmentSpecRequestController::class, 'approve']);
        Route::post('pk-catalog-requests/{specRequest}/reject', [PeriodicEquipmentSpecRequestController::class, 'reject']);
        // Tesisat raporunda geçip katalogda olmayan sistem talepleri.
        Route::get('pk-installation-system-requests', [PkInstallationSystemRequestController::class, 'index']);
        Route::post('pk-installation-system-requests/{systemRequest}/approve', [PkInstallationSystemRequestController::class, 'approve']);
        Route::post('pk-installation-system-requests/{systemRequest}/reject', [PkInstallationSystemRequestController::class, 'reject']);
        Route::post('pk-equipment/{pkEquipment}/photo', [PkEquipmentController::class, 'storePhoto']);
        Route::delete('pk-equipment/{pkEquipment}/photo', [PkEquipmentController::class, 'destroyPhoto']);

        // Test ekranı: yeni rapor algılaması (kriterler hariç) - fixture listele / göster / yeni analiz.
        Route::get('pk-report-analyses', [PkReportAnalysisTestController::class, 'index']);
        Route::get('pk-report-analyses/{fixtureId}', [PkReportAnalysisTestController::class, 'show']);
        Route::post('pk-report-analyses', [PkReportAnalysisTestController::class, 'store']);
        Route::post('pk-report-analyses/{fixtureId}/tables', [PkReportAnalysisTestController::class, 'rereadTables']);
    });
