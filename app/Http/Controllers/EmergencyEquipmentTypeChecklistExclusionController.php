<?php

namespace App\Http\Controllers;

use App\Models\EmergencyEquipmentType;
use App\Models\EmergencyEquipmentTypeChecklistItem;
use App\Services\EmergencyEquipmentChecklistExclusionService;
use Illuminate\Http\JsonResponse;

class EmergencyEquipmentTypeChecklistExclusionController extends Controller
{
    public function __construct(
        private EmergencyEquipmentChecklistExclusionService $service
    ) {
    }

    public function store(
        EmergencyEquipmentType $emergencyEquipmentType,
        EmergencyEquipmentTypeChecklistItem $checklistItem
    ): JsonResponse {
        $this->service->exclude($emergencyEquipmentType, $checklistItem);

        return response()->json([
            'message' => 'Madde bu alt kategori için kapsam dışı bırakıldı.',
        ]);
    }

    public function destroy(
        EmergencyEquipmentType $emergencyEquipmentType,
        EmergencyEquipmentTypeChecklistItem $checklistItem
    ): JsonResponse {
        $this->service->include($emergencyEquipmentType, $checklistItem);

        return response()->json([
            'message' => 'Madde tekrar kapsama alındı.',
        ]);
    }
}
