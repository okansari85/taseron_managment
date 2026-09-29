<?php

namespace App\Http\Controllers;

use App\Models\PeriodicEquipmentSpecRequest;
use App\Services\PeriodicEquipmentSpecRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// pktakip: katalog dışı teknik özellik talepleri (uzman talep eder, yetkili onaylar / reddeder).
class PeriodicEquipmentSpecRequestController extends Controller
{
    public function __construct(private PeriodicEquipmentSpecRequestService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($request->user()));
    }

    public function approve(Request $request, PeriodicEquipmentSpecRequest $specRequest): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'map_key' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->service->approve($specRequest, $data, $request->user());

        return response()->json($this->service->list($request->user()));
    }

    public function reject(Request $request, PeriodicEquipmentSpecRequest $specRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->service->reject($specRequest, $data['note'] ?? null, $request->user());

        return response()->json($this->service->list($request->user()));
    }
}
