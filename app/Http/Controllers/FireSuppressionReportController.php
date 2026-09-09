<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFireSuppressionReportRequest;
use App\Models\FireSuppressionReport;
use App\Models\LocationBusinessEntity;
use App\Services\FireSuppressionReportService;
use Illuminate\Http\JsonResponse;

class FireSuppressionReportController extends Controller
{
    public function __construct(
        private FireSuppressionReportService $service
    ) {
    }

    public function index(LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all($locationBusinessEntity),
        ]);
    }

    public function show(FireSuppressionReport $fireSuppressionReport): JsonResponse
    {
        return response()->json([
            'data' => $this->service->find($fireSuppressionReport),
        ]);
    }

    public function store(
        StoreFireSuppressionReportRequest $request,
        LocationBusinessEntity $locationBusinessEntity
    ): JsonResponse {
        $report = $this->service->create(
            $locationBusinessEntity,
            $request->validated(),
            $request->file('file'),
            $request->user()
        );

        return response()->json([
            'message' => 'Rapor başarıyla yüklendi.',
            'data' => $report,
        ], 201);
    }

    public function destroy(FireSuppressionReport $fireSuppressionReport): JsonResponse
    {
        $this->service->delete($fireSuppressionReport);

        return response()->json([
            'message' => 'Rapor silindi.',
        ]);
    }
}
