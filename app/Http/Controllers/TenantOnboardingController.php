<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenantOnboardingRequest;
use App\Services\TenantOnboardingService;
use Illuminate\Http\JsonResponse;

class TenantOnboardingController extends Controller
{
    public function __construct(
        private TenantOnboardingService $service
    ) {
    }

    public function store(
        TenantOnboardingRequest $request
    ): JsonResponse {
        $result = $this->service->create(
            $request->validated()
        );

        return response()->json([
            'message' => 'Tenant onboarding başarıyla tamamlandı.',
            'data' => $result,
        ], 201);
    }
}
