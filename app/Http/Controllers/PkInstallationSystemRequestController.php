<?php

namespace App\Http\Controllers;

use App\Models\PeriodicEquipmentSpecRequest;
use App\Services\PkInstallationSystemRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// pktakip: tesisat raporunda geçip katalogda olmayan sistem talepleri (uzman talep eder, yetkili onaylar / reddeder).
class PkInstallationSystemRequestController extends Controller
{
    public function __construct(private PkInstallationSystemRequestService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($request->user()));
    }

    public function approve(Request $request, PeriodicEquipmentSpecRequest $systemRequest): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'system_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->service->approve($systemRequest, $data, $request->user());

        return response()->json($this->service->list($request->user()));
    }

    public function reject(Request $request, PeriodicEquipmentSpecRequest $systemRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->service->reject($systemRequest, $data['note'] ?? null, $request->user());

        return response()->json($this->service->list($request->user()));
    }
}
