<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmergencyEquipmentInspectionRequest;
use App\Http\Requests\UpdateEmergencyEquipmentInspectionRequest;
use App\Models\EmergencyEquipmentInspection;
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
        $items = $request->validated('items', []) ?? [];
        $itemFiles = $request->file('items', []);
        foreach ($items as $index => &$item) {
            $item['photo'] = $itemFiles[$index]['photo'] ?? null;
        }
        unset($item);

        $inspection = $this->service->create(
            $locationEmergencyEquipment,
            [...$request->validated(), 'items' => $items, 'photos' => $request->file('photos', [])],
            $request->user()
        );

        return response()->json([
            'message' => 'Denetim kaydı oluşturuldu.',
            'data' => $inspection,
        ], 201);
    }

    public function update(
        UpdateEmergencyEquipmentInspectionRequest $request,
        EmergencyEquipmentInspection $inspection
    ): JsonResponse {
        $items = $request->validated('items', []) ?? [];
        $itemFiles = $request->file('items', []);
        foreach ($items as $index => &$item) {
            $item['photo'] = $itemFiles[$index]['photo'] ?? null;
        }
        unset($item);

        $inspection = $this->service->update(
            $inspection,
            [...$request->validated(), 'items' => $items, 'photos' => $request->file('photos', [])]
        );

        return response()->json([
            'message' => 'Denetim kaydı güncellendi.',
            'data' => $inspection,
        ]);
    }
}
