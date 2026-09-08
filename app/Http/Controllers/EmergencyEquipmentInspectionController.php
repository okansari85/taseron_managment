<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmergencyEquipmentInspectionRequest;
use App\Models\LocationEmergencyEquipment;
use App\Services\EmergencyEquipmentInspectionService;
use Illuminate\Http\JsonResponse;

class EmergencyEquipmentInspectionController extends Controller
{
    public function __construct(
        private EmergencyEquipmentInspectionService $service
    ) {
    }

    public function index(LocationEmergencyEquipment $locationEmergencyEquipment): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all($locationEmergencyEquipment),
        ]);
    }

    public function store(
        StoreEmergencyEquipmentInspectionRequest $request,
        LocationEmergencyEquipment $locationEmergencyEquipment
    ): JsonResponse {
        $inspection = $this->service->create(
            $locationEmergencyEquipment,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'message' => 'Denetim kaydı oluşturuldu.',
            'data' => $inspection,
        ], 201);
    }
}
