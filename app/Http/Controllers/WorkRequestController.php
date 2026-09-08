<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProposeWorkRequestDateRequest;
use App\Http\Requests\StoreWorkRequestRequest;
use App\Http\Requests\UpdateWorkRequestStatusRequest;
use App\Models\WorkRequest;
use App\Services\WorkRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkRequestController extends Controller
{
    public function __construct(
        private WorkRequestService $service
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $contractorId = $request->query('contractor_id');

        return response()->json([
            'data' => $this->service->list($contractorId ? (int) $contractorId : null),
        ]);
    }

    public function store(StoreWorkRequestRequest $request): JsonResponse
    {
        $workRequest = $this->service->create($request->validated(), $request->user());

        return response()->json([
            'message' => 'İş talebi oluşturuldu.',
            'data' => $workRequest,
        ], 201);
    }

    public function updateStatus(UpdateWorkRequestStatusRequest $request, WorkRequest $workRequest): JsonResponse
    {
        $updated = $this->service->updateStatus($workRequest, $request->validated()['status']);

        return response()->json([
            'message' => 'İş talebi durumu güncellendi.',
            'data' => $updated,
        ]);
    }

    public function myRequests(Request $request): JsonResponse
    {
        $contractorId = $request->user()?->contractor_id;

        if (! $contractorId) {
            return response()->json(['message' => 'Bu kullanıcı bir taşerona bağlı değil.'], 403);
        }

        return response()->json([
            'data' => $this->service->listForContractorUser((int) $contractorId),
        ]);
    }

    public function proposeDate(ProposeWorkRequestDateRequest $request, WorkRequest $workRequest): JsonResponse
    {
        $contractorId = $request->user()?->contractor_id;

        if (! $contractorId) {
            return response()->json(['message' => 'Bu kullanıcı bir taşerona bağlı değil.'], 403);
        }

        $updated = $this->service->proposeDate($workRequest, (int) $contractorId, $request->validated()['proposed_date']);

        return response()->json([
            'message' => 'Alternatif tarih önerildi.',
            'data' => $updated,
        ]);
    }

    public function acceptProposedDate(WorkRequest $workRequest): JsonResponse
    {
        $updated = $this->service->acceptProposedDate($workRequest);

        return response()->json([
            'message' => 'Önerilen tarih kabul edildi.',
            'data' => $updated,
        ]);
    }

    public function destroy(WorkRequest $workRequest): JsonResponse
    {
        $this->service->delete($workRequest);

        return response()->json(['message' => 'İş talebi silindi.']);
    }
}
