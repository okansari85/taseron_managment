<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOperationalRegionRequest;
use App\Http\Requests\UpdateOperationalRegionRequest;
use App\Models\Location;
use App\Models\OperationalRegion;
use App\Services\OperationalRegionService;
use Illuminate\Http\JsonResponse;

class OperationalRegionController extends Controller
{
    public function __construct(
        private OperationalRegionService $service
    ) {
    }

    public function index(Location $location): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all($location),
        ]);
    }

    public function store(
        StoreOperationalRegionRequest $request,
        Location $location
    ): JsonResponse {
        $operationalRegion = $this->service->create(
            $location,
            $request->validated()
        );

        return response()->json([
            'message' => 'Operasyonel alan başarıyla oluşturuldu.',
            'data' => $operationalRegion,
        ], 201);
    }

    public function show(
        Location $location,
        OperationalRegion $operationalRegion
    ): JsonResponse {
        $operationalRegion = $this->service->find(
            $location,
            $operationalRegion->id
        );

        return response()->json([
            'data' => $operationalRegion,
        ]);
    }

    public function update(
        UpdateOperationalRegionRequest $request,
        Location $location,
        OperationalRegion $operationalRegion
    ): JsonResponse {
        $operationalRegion = $this->service->update(
            $location,
            $operationalRegion,
            $request->validated()
        );

        return response()->json([
            'message' => 'Operasyonel alan başarıyla güncellendi.',
            'data' => $operationalRegion,
        ]);
    }

    public function destroy(
        Location $location,
        OperationalRegion $operationalRegion
    ): JsonResponse {
        $this->service->delete(
            $location,
            $operationalRegion
        );

        return response()->json([
            'message' => 'Operasyonel alan başarıyla silindi.',
        ]);
    }
}
