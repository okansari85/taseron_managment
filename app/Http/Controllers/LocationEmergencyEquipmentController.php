<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationEmergencyEquipmentRequest;
use App\Http\Requests\UpdateLocationEmergencyEquipmentRequest;
use App\Models\LocationBusinessEntity;
use App\Models\LocationEmergencyEquipment;
use App\Services\LocationEmergencyEquipmentService;
use Illuminate\Http\JsonResponse;

class LocationEmergencyEquipmentController extends Controller
{
    public function __construct(
        private LocationEmergencyEquipmentService $service
    ) {
    }

    public function index(LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all($locationBusinessEntity),
        ]);
    }

    public function store(
        StoreLocationEmergencyEquipmentRequest $request,
        LocationBusinessEntity $locationBusinessEntity
    ): JsonResponse {
        $equipment = $this->service->create($locationBusinessEntity, $request->validated());

        return response()->json([
            'message' => 'Ekipman şubeye başarıyla eklendi.',
            'data' => $equipment,
        ], 201);
    }

    public function update(
        UpdateLocationEmergencyEquipmentRequest $request,
        LocationEmergencyEquipment $locationEmergencyEquipment
    ): JsonResponse {
        $equipment = $this->service->update($locationEmergencyEquipment, $request->validated());

        return response()->json([
            'message' => 'Ekipman bilgileri güncellendi.',
            'data' => $equipment,
        ]);
    }

    public function destroy(LocationEmergencyEquipment $locationEmergencyEquipment): JsonResponse
    {
        $this->service->delete($locationEmergencyEquipment);

        return response()->json([
            'message' => 'Ekipman silindi.',
        ]);
    }
}
