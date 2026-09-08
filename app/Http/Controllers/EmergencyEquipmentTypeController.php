<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmergencyEquipmentTypeRequest;
use App\Http\Requests\UpdateEmergencyEquipmentTypeRequest;
use App\Models\EmergencyEquipmentType;
use App\Services\EmergencyEquipmentTypeService;
use Illuminate\Http\JsonResponse;

class EmergencyEquipmentTypeController extends Controller
{
    public function __construct(
        private EmergencyEquipmentTypeService $service
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all(),
        ]);
    }

    public function store(StoreEmergencyEquipmentTypeRequest $request): JsonResponse
    {
        $equipmentType = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Ekipman türü başarıyla oluşturuldu.',
            'data' => $equipmentType,
        ], 201);
    }

    public function show(EmergencyEquipmentType $emergencyEquipmentType): JsonResponse
    {
        return response()->json([
            'data' => $this->service->find($emergencyEquipmentType->id),
        ]);
    }

    public function update(UpdateEmergencyEquipmentTypeRequest $request, EmergencyEquipmentType $emergencyEquipmentType): JsonResponse
    {
        $equipmentType = $this->service->update($emergencyEquipmentType, $request->validated());

        return response()->json([
            'message' => 'Ekipman türü başarıyla güncellendi.',
            'data' => $equipmentType,
        ]);
    }

    public function destroy(EmergencyEquipmentType $emergencyEquipmentType): JsonResponse
    {
        $this->service->delete($emergencyEquipmentType);

        return response()->json([
            'message' => 'Ekipman türü başarıyla silindi.',
        ]);
    }
}
