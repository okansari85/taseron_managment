<?php

namespace App\Http\Controllers;

use App\Services\FireSafetyDashboardService;
use Illuminate\Http\JsonResponse;

class FireSafetyDashboardController extends Controller
{
    public function __construct(
        private FireSafetyDashboardService $service
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->build(),
        ]);
    }
}
