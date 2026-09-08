<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmergencyEquipmentTypeChecklistItemRequest;
use App\Http\Requests\UpdateEmergencyEquipmentTypeChecklistItemRequest;
use App\Models\EmergencyEquipmentType;
use App\Models\EmergencyEquipmentTypeChecklistItem;
use App\Services\EmergencyEquipmentTypeChecklistItemService;
use Illuminate\Http\JsonResponse;

class EmergencyEquipmentTypeChecklistItemController extends Controller
{
    public function __construct(
        private EmergencyEquipmentTypeChecklistItemService $service
    ) {
    }

    public function index(EmergencyEquipmentType $emergencyEquipmentType): JsonResponse
    {
        return response()->json([
            'data' => $this->service->listForType($emergencyEquipmentType),
        ]);
    }

    public function store(
        StoreEmergencyEquipmentTypeChecklistItemRequest $request,
        EmergencyEquipmentType $emergencyEquipmentType
    ): JsonResponse {
        $item = $this->service->attach($emergencyEquipmentType, $request->validated());

        return response()->json([
            'message' => 'Checklist maddesi eklendi.',
            'data' => $item,
        ], 201);
    }

    public function update(
        UpdateEmergencyEquipmentTypeChecklistItemRequest $request,
        EmergencyEquipmentType $emergencyEquipmentType,
        EmergencyEquipmentTypeChecklistItem $checklistItem
    ): JsonResponse {
        $item = $this->service->update($checklistItem, $request->validated());

        return response()->json([
            'message' => 'Checklist maddesi güncellendi.',
            'data' => $item,
        ]);
    }

    public function destroy(
        EmergencyEquipmentType $emergencyEquipmentType,
        EmergencyEquipmentTypeChecklistItem $checklistItem
    ): JsonResponse {
        $this->service->delete($checklistItem);

        return response()->json([
            'message' => 'Checklist maddesi kaldırıldı.',
        ]);
    }
}
