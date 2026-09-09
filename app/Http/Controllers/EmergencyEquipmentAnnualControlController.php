<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmergencyEquipmentAnnualControlReportRequest;
use App\Models\EmergencyEquipmentAnnualControlReport;
use App\Models\LocationBusinessEntity;
use App\Services\EmergencyEquipmentAnnualControlService;
use Illuminate\Http\JsonResponse;

class EmergencyEquipmentAnnualControlController extends Controller
{
    public function __construct(
        private EmergencyEquipmentAnnualControlService $service
    ) {
    }

    public function index(LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all($locationBusinessEntity),
        ]);
    }

    public function show(EmergencyEquipmentAnnualControlReport $annualControlReport): JsonResponse
    {
        return response()->json([
            'data' => $this->service->find($annualControlReport),
        ]);
    }

    public function store(
        StoreEmergencyEquipmentAnnualControlReportRequest $request,
        LocationBusinessEntity $locationBusinessEntity
    ): JsonResponse {
        $report = $this->service->create(
            $locationBusinessEntity,
            $request->validated(),
            $request->file('file'),
            $request->user()
        );

        return response()->json([
            'message' => 'Yıllık kontrol raporu yüklendi.',
            'data' => $report,
        ], 201);
    }

    public function destroy(EmergencyEquipmentAnnualControlReport $annualControlReport): JsonResponse
    {
        $this->service->delete($annualControlReport);

        return response()->json([
            'message' => 'Yıllık kontrol raporu silindi.',
        ]);
    }
}
