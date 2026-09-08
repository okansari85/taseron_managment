<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmergencyEquipmentTypeTipOptionRequest;
use App\Http\Requests\UpdateEmergencyEquipmentTypeTipOptionRequest;
use App\Models\EmergencyEquipmentType;
use App\Models\EmergencyEquipmentTypeTipOption;
use App\Services\EmergencyEquipmentTypeTipOptionService;
use Illuminate\Http\JsonResponse;

class EmergencyEquipmentTypeTipOptionController extends Controller
{
    public function __construct(
        private EmergencyEquipmentTypeTipOptionService $service
    ) {
    }

    public function index(EmergencyEquipmentType $emergencyEquipmentType): JsonResponse
    {
        return response()->json([
            'data' => $this->service->listForType($emergencyEquipmentType),
        ]);
    }

    public function store(
        StoreEmergencyEquipmentTypeTipOptionRequest $request,
        EmergencyEquipmentType $emergencyEquipmentType
    ): JsonResponse {
        $option = $this->service->attach($emergencyEquipmentType, $request->validated());

        return response()->json([
            'message' => 'Tip seçeneği eklendi.',
            'data' => $option,
        ], 201);
    }

    public function update(
        UpdateEmergencyEquipmentTypeTipOptionRequest $request,
        EmergencyEquipmentType $emergencyEquipmentType,
        EmergencyEquipmentTypeTipOption $tipOption
    ): JsonResponse {
        $option = $this->service->update($tipOption, $request->validated());

        return response()->json([
            'message' => 'Tip seçeneği güncellendi.',
            'data' => $option,
        ]);
    }

    public function destroy(
        EmergencyEquipmentType $emergencyEquipmentType,
        EmergencyEquipmentTypeTipOption $tipOption
    ): JsonResponse {
        $this->service->delete($tipOption);

        return response()->json([
            'message' => 'Tip seçeneği kaldırıldı.',
        ]);
    }
}
