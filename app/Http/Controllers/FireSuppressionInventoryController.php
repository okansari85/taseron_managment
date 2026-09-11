<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFireSuppressionInventoryItemRequest;
use App\Http\Requests\UpdateFireSuppressionInventoryItemRequest;
use App\Models\FireSuppressionInventoryItem;
use App\Models\LocationBusinessEntity;
use App\Services\FireSuppressionInventoryService;
use Illuminate\Http\JsonResponse;

class FireSuppressionInventoryController extends Controller
{
    public function __construct(
        private FireSuppressionInventoryService $service
    ) {
    }

    public function index(LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all($locationBusinessEntity),
        ]);
    }

    public function summary(LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        return response()->json([
            'data' => $this->service->summary($locationBusinessEntity),
        ]);
    }

    public function store(
        StoreFireSuppressionInventoryItemRequest $request,
        LocationBusinessEntity $locationBusinessEntity
    ): JsonResponse {
        $item = $this->service->create($locationBusinessEntity, $request->validated());

        return response()->json([
            'message' => 'Sistem bileşeni başarıyla eklendi.',
            'data' => $item,
        ], 201);
    }

    public function update(
        UpdateFireSuppressionInventoryItemRequest $request,
        FireSuppressionInventoryItem $fireSuppressionInventoryItem
    ): JsonResponse {
        $item = $this->service->update($fireSuppressionInventoryItem, $request->validated());

        return response()->json([
            'message' => 'Sistem bileşeni güncellendi.',
            'data' => $item,
        ]);
    }

    public function destroy(FireSuppressionInventoryItem $fireSuppressionInventoryItem): JsonResponse
    {
        $this->service->delete($fireSuppressionInventoryItem);

        return response()->json([
            'message' => 'Sistem bileşeni silindi.',
        ]);
    }
}
