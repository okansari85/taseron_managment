<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFieldFindingRequest;
use App\Http\Requests\UpdateFieldFindingRequest;
use App\Models\FieldFinding;
use App\Models\LocationBusinessEntity;
use App\Services\FieldFindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldFindingController extends Controller
{
    public function __construct(
        private FieldFindingService $service
    ) {
    }

    public function index(LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all($locationBusinessEntity),
        ]);
    }

    public function store(
        StoreFieldFindingRequest $request,
        LocationBusinessEntity $locationBusinessEntity
    ): JsonResponse {
        $finding = $this->service->create(
            $locationBusinessEntity,
            [...$request->validated(), 'photos' => $request->file('photos', [])],
            $request->user()
        );

        return response()->json([
            'message' => 'Saha bulgusu kaydedildi.',
            'data' => $finding,
        ], 201);
    }

    public function update(
        UpdateFieldFindingRequest $request,
        FieldFinding $fieldFinding
    ): JsonResponse {
        $finding = $this->service->update(
            $fieldFinding,
            [...$request->validated(), 'photos' => $request->file('photos', [])]
        );

        return response()->json([
            'message' => 'Saha bulgusu güncellendi.',
            'data' => $finding,
        ]);
    }

    public function destroy(FieldFinding $fieldFinding): JsonResponse
    {
        $this->service->delete($fieldFinding);

        return response()->json([
            'message' => 'Saha bulgusu silindi.',
        ]);
    }
}
