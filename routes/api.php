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
use App\Http\Controllers\UserAuthorizationController;
use App\Http\Controllers\LocationExpertController;
use App\Http\Controllers\TenantBrandingController;
use App\Http\Controllers\WorkspaceContextController;
use App\Http\Controllers\WorkspaceThemeController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ActivityDocumentTypeController;
use App\Http\Controllers\EmergencyEquipmentInspectionController;
use App\Http\Controllers\EmergencyEquipmentAnnualControlController;
use App\Http\Controllers\FireSafetyDashboardController;
use App\Http\Controllers\EmergencyEquipmentTypeChecklistExclusionController;
use App\Http\Controllers\EmergencyEquipmentTypeTipOptionController;
use App\Http\Controllers\EmergencyEquipmentTypeChecklistItemController;
use App\Http\Controllers\EmergencyEquipmentTypeController;
use App\Http\Controllers\LocationEmergencyEquipmentController;
use App\Http\Controllers\FieldFindingController;
use App\Http\Controllers\FireSuppressionInventoryController;
use App\Http\Controllers\FireSuppressionReportController;
use App\Http\Controllers\WorkRequestController;
use App\Models\City;
use App\Models\District;

Route::get('/user', function (Request $request, \App\Services\UserScopeService $userScopeService) {
    $user = $request->user();
    $user->loadMissing('contractor.businessEntity');

    // Taşeron kullanıcısı tenant_id'yi kendi businessEntity'sinden alır.
    // Diğer personel rolleri (isg, security, operation, tenant) için tenant_id
    // Yetkilendirme ekranından atanan scope'lardan türetilir (önce açık
    // 'tenant' scope'u, yoksa organization/location/operational_region
    // scope'unun bağlı olduğu tenant — bkz. UserScopeService::resolveTenantId)
    // - süper admin gibi hiç scope'u olmayanlar için hâlâ null'dır (birden
    // fazla tenant'a erişebilir).
    $scopeTenantId = $userScopeService->resolveTenantId($user);

    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'roles' => $user->getRoleNames(),
        'contractor_id' => $user->contractor_id,
        'contractor' => $user->contractor ? [
            'id' => $user->contractor->id,
            'name' => $user->contractor->businessEntity?->name,
            'contractor_type' => $user->contractor->contractor_type,
            'tenant_id' => $user->contractor->businessEntity?->tenant_id,
        ] : null,
        'tenant_id' => $scopeTenantId,
    ]);
})->middleware('auth:sanctum');
Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    Route::middleware('web-role:super-admin')->group(function () {
        Route::post('users/{user}/impersonate', [UserAuthorizationController::class, 'impersonate']);
        Route::apiResource('tenants', TenantController::class);
        Route::post('tenant-onboarding', [TenantOnboardingController::class, 'store']);
    });

    Route::middleware(['tenant', 'workspace-context'])->group(function () {
        Route::middleware('web-role:super-admin')->group(function () {
            Route::apiResource('companies', CompanyController::class);
            Route::apiResource('brands', BrandController::class);
            Route::apiResource('locations.operational-regions', OperationalRegionController::class);

            Route::get('organizations/{organization}/companies', [OrganizationCompanyController::class, 'index']);
            Route::put('organizations/{organization}/companies', [OrganizationCompanyController::class, 'sync']);
            Route::post('organizations/{organization}/companies/{company}', [OrganizationCompanyController::class, 'attach']);
            Route::delete('organizations/{organization}/companies/{company}', [OrganizationCompanyController::class, 'detach']);

            Route::get('organizations/{organization}/locations', [OrganizationLocationController::class, 'index']);
            Route::put('organizations/{organization}/locations', [OrganizationLocationController::class, 'sync']);
            Route::post('organizations/{organization}/locations', [OrganizationLocationController::class, 'store']);
            Route::post('organizations/{organization}/locations/{location}', [OrganizationLocationController::class, 'attach']);
            Route::delete('organizations/{organization}/locations/{location}', [OrganizationLocationController::class, 'detach']);

            Route::get('brands/{brand}/locations', [BrandLocationController::class, 'index']);
            Route::put('brands/{brand}/locations', [BrandLocationController::class, 'sync']);
            Route::post('brands/{brand}/locations/{location}', [BrandLocationController::class, 'attach']);
            Route::delete('brands/{brand}/locations/{location}', [BrandLocationController::class, 'detach']);

            Route::get('locations/{location}/organization-contractors', [LocationController::class, 'organizationContractors']);
            Route::post('locations/{location}/business-entities', [LocationBusinessEntityController::class, 'store']);
            Route::put('locations/{location}/business-entities/{locationBusinessEntity}', [LocationBusinessEntityController::class, 'update']);
            Route::delete('locations/{location}/business-entities/{locationBusinessEntity}', [LocationBusinessEntityController::class, 'destroy']);

            Route::get('users/authorization', [UserAuthorizationController::class, 'index']);
            Route::post('users', [UserAuthorizationController::class, 'store']);
            Route::get('users/{user}/authorization', [UserAuthorizationController::class, 'show']);
            Route::patch('users/{user}/profile', [UserAuthorizationController::class, 'updateProfile']);
            Route::delete('users/{user}', [UserAuthorizationController::class, 'destroy']);
            Route::post('users/{user}/role', [UserAuthorizationController::class, 'assignRole']);
            Route::put('users/{user}/permissions', [UserAuthorizationController::class, 'permissions']);
            Route::put('users/{user}/forbidden-permissions', [UserAuthorizationController::class, 'forbiddenPermissions']);
            Route::get('roles', [UserAuthorizationController::class, 'roles']);
            Route::get('permissions', [UserAuthorizationController::class, 'permissionList']);
            Route::put('roles/{role}/permissions', [UserAuthorizationController::class, 'updateRolePermissions']);
            Route::get('users/{user}/scopes', [UserAuthorizationController::class, 'scopes']);
            Route::put('users/{user}/scopes', [UserAuthorizationController::class, 'syncScopes']);
            Route::post('users/{user}/scopes', [UserAuthorizationController::class, 'attachScope']);
            Route::delete('users/{user}/scopes', [UserAuthorizationController::class, 'detachScope']);

            Route::get('users/{user}/location-experts', [LocationExpertController::class, 'forUser']);

            Route::get('location-business-entities/{locationBusinessEntity}/experts', [LocationExpertController::class, 'index']);
            Route::post('location-business-entities/{locationBusinessEntity}/experts', [LocationExpertController::class, 'attach']);
            Route::delete('location-business-entities/{locationBusinessEntity}/experts/{user}', [LocationExpertController::class, 'detach']);
        });

        // Faaliyetler / evrak tanımlamaları — hem süper admin hem de gerçek
        // tenant (grup yöneticisi) rolü kendi verilerini yönetebilsin diye
        // super-admin'e özel gruptan AYRI tutuldu. 'isg' burada eklendi çünkü
        // Yangın Denetimi akışı (mobil-uyumlu isg-portal) lokasyon/şube/ekipman/
        // denetim okuma+yazma erişimine ihtiyaç duyuyor — isg-portal UI'ı zaten
        // sadece kendi denetim ekranlarını gösteriyor, admin ekranlarına (ör.
        // ekipman türü tanımlama) bağlantı vermiyor.
        Route::middleware('web-role:super-admin,tenant,isg')->group(function () {
            // Lokasyonlar listesi + il/ilçe dropdown'ları — tenant (grup yöneticisi)
            // de kendi lokasyonlarını görebilsin/yönetebilsin diye süper admin'e
            // özel gruptan taşındı (aynı sebep/desen: organizations, contractors).
            Route::get('cities', fn () => response()->json(City::query()->orderBy('name')->get(['id','name'])));
            Route::get('cities/{city}/districts', fn (City $city) => response()->json($city->districts()->orderBy('name')->get(['id','city_id','name'])));
            // apiResource'tan ÖNCE tanımlanmalı, yoksa 'locations/{location}'
            // route-model-binding'i bu path'i bir id sanmaya çalışır.
            Route::get('locations/multi-branch-buildings', [LocationController::class, 'multiBranchBuildings']);
            Route::apiResource('locations', LocationController::class);
            Route::get('location-business-entities', [LocationBusinessEntityController::class, 'forTenant']);
            // "Şube Seç" ekranı (Yangın Denetimi akışı) için — CRUD admin
            // grubunda kalıyor, sadece bu okuma isg'ye de açıldı.
            Route::get('locations/{location}/business-entities', [LocationBusinessEntityController::class, 'index']);
            // "Yeni Şube" ekranındaki Grup/Firma/Marka seçimi için — grup ve
            // şirket CRUD'u süper admin'de kalıyor, sadece bu salt-okuma liste
            // tenant rolüne açıldı (locations ile aynı sebep/desen).
            Route::get('organization-companies', [OrganizationCompanyController::class, 'indexForTenant']);

            Route::apiResource('activities', ActivityController::class);
            Route::get('activities/{activity}/document-types', [ActivityDocumentTypeController::class, 'index']);
            Route::post('activities/{activity}/document-types', [ActivityDocumentTypeController::class, 'store']);
            Route::put('activities/{activity}/document-types/{activityDocumentType}', [ActivityDocumentTypeController::class, 'update']);
            Route::delete('activities/{activity}/document-types/{activityDocumentType}', [ActivityDocumentTypeController::class, 'destroy']);
        
            Route::apiResource('emergency-equipment-types', EmergencyEquipmentTypeController::class);
            Route::get('emergency-equipment-types/{emergencyEquipmentType}/checklist-items', [EmergencyEquipmentTypeChecklistItemController::class, 'index']);
            Route::post('emergency-equipment-types/{emergencyEquipmentType}/checklist-items', [EmergencyEquipmentTypeChecklistItemController::class, 'store']);
            Route::put('emergency-equipment-types/{emergencyEquipmentType}/checklist-items/{checklistItem}', [EmergencyEquipmentTypeChecklistItemController::class, 'update']);
            Route::delete('emergency-equipment-types/{emergencyEquipmentType}/checklist-items/{checklistItem}', [EmergencyEquipmentTypeChecklistItemController::class, 'destroy']);
            Route::post('emergency-equipment-types/{emergencyEquipmentType}/checklist-exclusions/{checklistItem}', [EmergencyEquipmentTypeChecklistExclusionController::class, 'store']);
            Route::delete('emergency-equipment-types/{emergencyEquipmentType}/checklist-exclusions/{checklistItem}', [EmergencyEquipmentTypeChecklistExclusionController::class, 'destroy']);
            Route::get('emergency-equipment-types/{emergencyEquipmentType}/tip-options', [EmergencyEquipmentTypeTipOptionController::class, 'index']);
            Route::post('emergency-equipment-types/{emergencyEquipmentType}/tip-options', [EmergencyEquipmentTypeTipOptionController::class, 'store']);
            Route::put('emergency-equipment-types/{emergencyEquipmentType}/tip-options/{tipOption}', [EmergencyEquipmentTypeTipOptionController::class, 'update']);
            Route::delete('emergency-equipment-types/{emergencyEquipmentType}/tip-options/{tipOption}', [EmergencyEquipmentTypeTipOptionController::class, 'destroy']);

            Route::get('location-business-entities/{locationBusinessEntity}/emergency-equipment', [LocationEmergencyEquipmentController::class, 'index']);
            Route::post('location-business-entities/{locationBusinessEntity}/emergency-equipment', [LocationEmergencyEquipmentController::class, 'store']);
            Route::put('emergency-equipment/{locationEmergencyEquipment}', [LocationEmergencyEquipmentController::class, 'update']);
            Route::delete('emergency-equipment/{locationEmergencyEquipment}', [LocationEmergencyEquipmentController::class, 'destroy']);

            Route::get('emergency-equipment/{locationEmergencyEquipment}/inspections', [EmergencyEquipmentInspectionController::class, 'index']);
            Route::post('emergency-equipment/{locationEmergencyEquipment}/inspections', [EmergencyEquipmentInspectionController::class, 'store']);
            Route::put('emergency-equipment-inspections/{inspection}', [EmergencyEquipmentInspectionController::class, 'update']);

            // YSC — Yıllık Periyodik Kontrol (Aylık Kontrol'den bağımsız, section 3/5).
            Route::get('location-business-entities/{locationBusinessEntity}/emergency-equipment-annual-controls', [EmergencyEquipmentAnnualControlController::class, 'index']);
            Route::post('location-business-entities/{locationBusinessEntity}/emergency-equipment-annual-controls', [EmergencyEquipmentAnnualControlController::class, 'store']);
            Route::post('location-business-entities/{locationBusinessEntity}/emergency-equipment-annual-controls/analyze', [EmergencyEquipmentAnnualControlController::class, 'analyze']);
            Route::get('emergency-equipment-annual-controls/{annualControlReport}', [EmergencyEquipmentAnnualControlController::class, 'show']);
            Route::delete('emergency-equipment-annual-controls/{annualControlReport}', [EmergencyEquipmentAnnualControlController::class, 'destroy']);

            Route::get('location-business-entities/{locationBusinessEntity}/field-findings', [FieldFindingController::class, 'index']);
            Route::post('location-business-entities/{locationBusinessEntity}/field-findings', [FieldFindingController::class, 'store']);
            Route::put('field-findings/{fieldFinding}', [FieldFindingController::class, 'update']);
            Route::delete('field-findings/{fieldFinding}', [FieldFindingController::class, 'destroy']);

            // Yangın Söndürme Sistemleri — Envanter (fiziksel tesisat kayıtları, YSC'den
            // ayrı bir domain — bkz. FireSuppressionInventoryItem model docblock'u).
            Route::get('location-business-entities/{locationBusinessEntity}/fire-suppression-inventory', [FireSuppressionInventoryController::class, 'index']);
            Route::get('location-business-entities/{locationBusinessEntity}/fire-suppression-inventory/summary', [FireSuppressionInventoryController::class, 'summary']);
            Route::post('location-business-entities/{locationBusinessEntity}/fire-suppression-inventory', [FireSuppressionInventoryController::class, 'store']);
            Route::put('fire-suppression-inventory/{fireSuppressionInventoryItem}', [FireSuppressionInventoryController::class, 'update']);
            Route::delete('fire-suppression-inventory/{fireSuppressionInventoryItem}', [FireSuppressionInventoryController::class, 'destroy']);

            // Yangın Söndürme Sistemleri — Raporlar (section 26/27).
            Route::get('location-business-entities/{locationBusinessEntity}/fire-suppression-reports', [FireSuppressionReportController::class, 'index']);
            Route::post('location-business-entities/{locationBusinessEntity}/fire-suppression-reports', [FireSuppressionReportController::class, 'store']);
            Route::post('location-business-entities/{locationBusinessEntity}/fire-suppression-reports/analyze', [FireSuppressionReportController::class, 'analyze']);
            Route::get('fire-suppression-reports/{fireSuppressionReport}', [FireSuppressionReportController::class, 'show']);
            Route::delete('fire-suppression-reports/{fireSuppressionReport}', [FireSuppressionReportController::class, 'destroy']);

            Route::get('fire-safety/dashboard', [FireSafetyDashboardController::class, 'show']);

            // Taşeron Yönetimi — organizasyonlar, taşeronlar, organizasyon-taşeron eşleştirmesi
            // ve iş talepleri. Faaliyetler/Yangın Modülü ile aynı sebeple: tenant (grup yöneticisi)
            // kendi taşeron portalını kullanabilsin diye süper admin'e özel gruptan taşındı (2026-09-06).
            Route::apiResource('organizations', OrganizationController::class);
            Route::apiResource('contractors', ContractorController::class);
            Route::get('contractors/{contractor}/locations', [ContractorController::class, 'locations']);

            Route::get('work-requests', [WorkRequestController::class, 'index']);
            Route::post('work-requests', [WorkRequestController::class, 'store']);
            Route::patch('work-requests/{workRequest}/status', [WorkRequestController::class, 'updateStatus']);
            Route::patch('work-requests/{workRequest}/accept-proposed-date', [WorkRequestController::class, 'acceptProposedDate']);
            Route::delete('work-requests/{workRequest}', [WorkRequestController::class, 'destroy']);

            Route::get('organization-contractors', [OrganizationContractorController::class, 'contractorsForTenant']);
            Route::post('organization-contractors/bulk', [OrganizationContractorController::class, 'bulkAttach']);
            Route::get('organizations/{organization}/contractors', [OrganizationContractorController::class, 'index']);
            Route::post('organizations/{organization}/contractors/{contractor}', [OrganizationContractorController::class, 'attach']);
            Route::delete('organizations/{organization}/contractors/{contractor}', [OrganizationContractorController::class, 'detach']);
        });

        // Taşeron Portalı — taşeron rolündeki kullanıcı SADECE kendi taşeronuna ait iş
        // taleplerini görür ve (henüz karara bağlanmamışsa) alternatif tarih önerebilir.
        // Personel/araç/ekipman/kimyasal beyanı bu gruba KASITLI OLARAK eklenmedi —
        // bkz. proje dokümanındaki Personel/MYK/SGK referans liste beklemesi.
        Route::middleware('web-role:contractor')->group(function () {
            Route::get('my/work-requests', [WorkRequestController::class, 'myRequests']);
            Route::patch('my/work-requests/{workRequest}/propose-date', [WorkRequestController::class, 'proposeDate']);
        });

        // Operasyon Portalı — iş talebi onay akışı taşeron ile operation rolü arasındadır
        // (İSG'nin bu akışla ilgisi yok; İSG'nin işi evrak onayıdır, o da henüz kurulmadı
        // çünkü Evrak Profili + Personel modülleri hâlâ yazılmadı — bkz. proje dokümanı).
        Route::middleware('web-role:operation')->group(function () {
            Route::get('operation/work-requests', [WorkRequestController::class, 'index']);
            Route::patch('operation/work-requests/{workRequest}/status', [WorkRequestController::class, 'updateStatus']);
        });

        // Sadece görsel marka bilgisi (logo) — hassas veri değil, bu yüzden
        // super-admin dışındaki roller (ör. contractor) için de açık.
        Route::get('tenant-branding', [TenantBrandingController::class, 'show']);

        // Kimliği doğrulanmış kullanıcının sidebar/header teması (grup rengi + varsayılan marka logosu).
        Route::get('workspace-theme', [WorkspaceThemeController::class, 'show']);

        // Header'daki aktif çalışma bağlamı seçici (Organizasyon → Lokasyon → Operasyonel Alan).
        // Sadece kullanıcının kendi scope'undan okur, salt-okuma.
        Route::get('workspace-context', [WorkspaceContextController::class, 'bootstrap']);
        Route::get('workspace-context/organizations', [WorkspaceContextController::class, 'organizations']);
        Route::get('workspace-context/locations', [WorkspaceContextController::class, 'locations']);
        Route::get('workspace-context/operational-areas', [WorkspaceContextController::class, 'operationalAreas']);
    });
});
