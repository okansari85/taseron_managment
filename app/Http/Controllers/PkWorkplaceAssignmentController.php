<?php

namespace App\Http\Controllers;

use App\Models\LocationBusinessEntity;
use App\Services\PkWorkplaceAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// OSGB / kurumsal hesapta uzman ↔ firma ataması (Ayarlar → Firmalar): yönetici ve operasyon yöneticisi.
class PkWorkplaceAssignmentController extends Controller
{
    public function __construct(private PkWorkplaceAssignmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($this->service->assignerTenant($request->user())));
    }

    // Yönetici ve operasyon yöneticisi: hesaptaki bütün işyerleri (Firmalarım, üst seçici, Genel Bakış).
    public function workplaces(Request $request): JsonResponse
    {
        return response()->json($this->service->accountWorkplaces($this->service->assignerTenant($request->user())));
    }

    public function update(Request $request, LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        $tenant = $this->service->assignerTenant($request->user());
        $data = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['integer']]);

        return response()->json($this->service->sync($tenant, $locationBusinessEntity, $data['user_ids']));
    }
}
