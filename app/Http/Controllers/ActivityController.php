<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreActivityRequest;
use App\Http\Requests\UpdateActivityRequest;
use App\Models\Activity;
use App\Services\ActivityService;
use Illuminate\Http\JsonResponse;

class ActivityController extends Controller
{
    public function __construct(
        private ActivityService $service
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all(),
        ]);
    }

    public function store(StoreActivityRequest $request): JsonResponse
    {
        $activity = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Faaliyet başarıyla oluşturuldu.',
            'data' => $activity,
        ], 201);
    }

    public function show(Activity $activity): JsonResponse
    {
        return response()->json([
            'data' => $this->service->find($activity->id),
        ]);
    }

    public function update(UpdateActivityRequest $request, Activity $activity): JsonResponse
    {
        $activity = $this->service->update($activity, $request->validated());

        return response()->json([
            'message' => 'Faaliyet başarıyla güncellendi.',
            'data' => $activity,
        ]);
    }

    public function destroy(Activity $activity): JsonResponse
    {
        $this->service->delete($activity);

        return response()->json([
            'message' => 'Faaliyet başarıyla silindi.',
        ]);
    }
}
