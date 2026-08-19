<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncBrandLocationsRequest;
use App\Models\Brand;
use App\Models\Location;
use App\Services\BrandLocationService;
use Illuminate\Http\JsonResponse;

class BrandLocationController extends Controller
{
    public function __construct(
        private BrandLocationService $service
    ) {
    }

    public function index(Brand $brand): JsonResponse
    {
        return response()->json([
            'data' => $brand->locations()->get(),
        ]);
    }

    public function attach(
        Brand $brand,
        Location $location
    ): JsonResponse {
        $this->service->attach(
            $brand,
            $location
        );

        return response()->json([
            'message' => 'Lokasyon markaya başarıyla bağlandı.',
        ]);
    }

    public function detach(
        Brand $brand,
        Location $location
    ): JsonResponse {
        $this->service->detach(
            $brand,
            $location
        );

        return response()->json([
            'message' => 'Lokasyon markadan başarıyla çıkarıldı.',
        ]);
    }

    public function sync(
        SyncBrandLocationsRequest $request,
        Brand $brand
    ): JsonResponse {
        $this->service->sync(
            $brand,
            $request->validated('location_ids')
        );

        return response()->json([
            'message' => 'Marka lokasyonları başarıyla güncellendi.',
        ]);
    }
}
