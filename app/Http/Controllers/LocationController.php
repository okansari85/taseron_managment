<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use App\Services\LocationService;
use App\Services\OrganizationContractorService;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function __construct(
        private LocationService $service,
        private OrganizationContractorService $organizationContractorService
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            $this->service->all()
        );
    }

    public function store(
        StoreLocationRequest $request
    ): JsonResponse {
        $location = $this->service->create(
            $request->validated()
        );

        return response()->json(
            $location,
            201
        );
    }

    public function show(
        Location $location
    ): JsonResponse {
        return response()->json(
            $this->service->find($location->id)
        );
    }

    public function organizationContractors(Location $location): JsonResponse
    {
        return response()->json(
            $this->organizationContractorService->contractorsForLocation($location)
        );
    }

    public function update(
        UpdateLocationRequest $request,
        Location $location
    ): JsonResponse {
        $location = $this->service->update(
            $location,
            $request->validated()
        );

        return response()->json(
            $location
        );
    }

    public function destroy(
        Location $location
    ): JsonResponse {
        $this->service->delete($location);

        return response()->json([
            'message' => 'Lokasyon başarıyla silindi.',
        ]);
    }
}