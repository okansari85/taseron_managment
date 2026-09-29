<?php

use App\Http\Controllers\Api\ExpertController;
use App\Http\Controllers\PkAccountController;
use App\Http\Controllers\PkAccountOverviewController;
use App\Http\Controllers\PkAccountUserController;
use App\Http\Controllers\PkCompanyTitleController;
use App\Http\Controllers\PkWorkplaceAssignmentController;
use Illuminate\Support\Facades\Route;

Route::post('experts/set-password', [ExpertController::class, 'setPassword']);

Route::middleware(['auth:sanctum', 'web-role:super-admin'])->group(function () {
    Route::post('experts', [ExpertController::class, 'store']);
    Route::post('experts/{tenant}/impersonate', [ExpertController::class, 'impersonate']);
    Route::post('experts/{tenant}/resend-invitation', [ExpertController::class, 'resendInvitation']);
});

// PKTakip hesapları: süper admin OSGB / kurumsal hesap açar, daveti yeniden gönderir, her hesap türüne girer.
Route::middleware(['auth:sanctum', 'web-role:super-admin'])->group(function () {
    Route::post('pk-accounts', [PkAccountController::class, 'store']);
    Route::post('pk-accounts/{tenant}/impersonate', [PkAccountController::class, 'impersonate']);
    Route::post('pk-accounts/{tenant}/resend-invitation', [PkAccountController::class, 'resendInvitation']);
    Route::post('pk-accounts/{tenant}/users/{user}/impersonate', [PkAccountUserController::class, 'impersonate']);
});

// Oturumdaki kullanıcının hesabı (panelde hesap türüne göre yazılar).
Route::middleware('auth:sanctum')->get('pk-account', [PkAccountController::class, 'current']);

// OSGB / kurumsal hesap kullanıcıları (Ayarlar → Kullanıcılar): yetki kontrolü serviste (hesabın aktif yöneticisi).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('pk-account/users', [PkAccountUserController::class, 'index']);
    Route::post('pk-account/users', [PkAccountUserController::class, 'store']);
    Route::patch('pk-account/users/{user}', [PkAccountUserController::class, 'update']);
    Route::post('pk-account/users/{user}/deactivate', [PkAccountUserController::class, 'deactivate']);
    Route::post('pk-account/users/{user}/activate', [PkAccountUserController::class, 'activate']);
    Route::post('pk-account/users/{user}/resend-invitation', [PkAccountUserController::class, 'resendInvitation']);
    // Uzman ↔ firma ataması (yönetici ve operasyon yöneticisi).
    Route::get('pk-account/assignments', [PkWorkplaceAssignmentController::class, 'index']);
    // Yönetici ve operasyon yöneticisinin işyerleri: hesabın tamamı.
    Route::get('pk-account/workplaces', [PkWorkplaceAssignmentController::class, 'workplaces']);
    Route::patch('pk-account/assignments/{locationBusinessEntity}', [PkWorkplaceAssignmentController::class, 'update']);
    // Genel Bakış: uzman / müşteri bazında durum (yönetici ve operasyon yöneticisi).
    Route::get('pk-account/overview', PkAccountOverviewController::class);
    // Firma ünvanları (bir kez tanımlanır, firmalara bağlanır).
    Route::get('pk-company-titles', [PkCompanyTitleController::class, 'index']);
    Route::patch('pk-company-titles/{businessEntity}', [PkCompanyTitleController::class, 'update'])->whereNumber('businessEntity');
});
