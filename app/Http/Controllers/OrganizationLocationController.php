<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncOrganizationLocationsRequest;
use App\Models\Location;
use App\Models\Organization;
use App\Services\OrganizationLocationService;
use Illuminate\Http\JsonResponse;

class OrganizationLocationController extends Controller
{
    public function __construct(
        private OrganizationLocationService $service
    ) {
    }

    public function attach(
        Organization $organization,
        Location $location
    ): JsonResponse {
        $this->service->attach(
            $organization,
            $location
        );

        return response()->json([
            'message' => 'Lokasyon organizasyona başarıyla bağlandı.',
        ]);
    }

    public function detach(
        Organization $organization,
        Location $location
    ): JsonResponse {
        $this->service->detach(
            $organization,
            $location
        );

        return response()->json([
            'message' => 'Lokasyon organizasyondan başarıyla çıkarıldı.',
        ]);
    }

    public function sync(
        SyncOrganizationLocationsRequest $request,
        Organization $organization
    ): JsonResponse {
        $this->service->sync(
            $organization,
            $request->validated('location_ids')
        );

        return response()->json([
            'message' => 'Organizasyon lokasyonları başarıyla güncellendi.',
        ]);
    }
}
