<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOperationalUnitRequest;
use App\Http\Requests\UpdateOperationalUnitRequest;
use App\Models\Location;
use App\Models\OperationalUnit;
use App\Services\OperationalUnitService;
use Illuminate\Http\JsonResponse;

class OperationalUnitController extends Controller
{
    public function __construct(
        private OperationalUnitService $service
    ) {
    }

    public function index(Location $location): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all($location),
        ]);
    }

    public function store(
        StoreOperationalUnitRequest $request,
        Location $location
    ): JsonResponse {
        $operationalUnit = $this->service->create(
            $location,
            $request->validated()
        );

        return response()->json([
            'message' => 'Operasyonel birim başarıyla oluşturuldu.',
            'data' => $operationalUnit,
        ], 201);
    }

    public function show(
        Location $location,
        OperationalUnit $operationalUnit
    ): JsonResponse {
        $operationalUnit = $this->service->find(
            $location,
            $operationalUnit->id
        );

        return response()->json([
            'data' => $operationalUnit,
        ]);
    }

    public function update(
        UpdateOperationalUnitRequest $request,
        Location $location,
        OperationalUnit $operationalUnit
    ): JsonResponse {
        $operationalUnit = $this->service->update(
            $location,
            $operationalUnit,
            $request->validated()
        );

        return response()->json([
            'message' => 'Operasyonel birim başarıyla güncellendi.',
            'data' => $operationalUnit,
        ]);
    }

    public function destroy(
        Location $location,
        OperationalUnit $operationalUnit
    ): JsonResponse {
        $this->service->delete(
            $location,
            $operationalUnit
        );

        return response()->json([
            'message' => 'Operasyonel birim başarıyla silindi.',
        ]);
    }
}
