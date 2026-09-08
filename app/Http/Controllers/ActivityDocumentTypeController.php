<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreActivityDocumentTypeRequest;
use App\Http\Requests\UpdateActivityDocumentTypeRequest;
use App\Models\Activity;
use App\Models\ActivityDocumentType;
use App\Services\ActivityDocumentTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityDocumentTypeController extends Controller
{
    public function __construct(
        private ActivityDocumentTypeService $service
    ) {
    }

    public function index(Request $request, Activity $activity): JsonResponse
    {
        $target = $request->query('target');

        return response()->json([
            'data' => $this->service->listForActivity($activity, is_string($target) ? $target : null),
        ]);
    }

    public function store(StoreActivityDocumentTypeRequest $request, Activity $activity): JsonResponse
    {
        $item = $this->service->attach($activity, $request->validated());

        return response()->json([
            'message' => 'Evrak başarıyla eklendi.',
            'data' => $item,
        ], 201);
    }

    public function update(
        UpdateActivityDocumentTypeRequest $request,
        Activity $activity,
        ActivityDocumentType $activityDocumentType
    ): JsonResponse {
        $item = $this->service->update($activityDocumentType, $request->validated());

        return response()->json([
            'message' => 'Evrak güncellendi.',
            'data' => $item,
        ]);
    }

    public function destroy(Activity $activity, ActivityDocumentType $activityDocumentType): JsonResponse
    {
        $this->service->delete($activityDocumentType);

        return response()->json([
            'message' => 'Evrak kaldırıldı.',
        ]);
    }
}
