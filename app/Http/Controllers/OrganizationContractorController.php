<?php

namespace App\Http\Controllers;

use App\Models\Contractor;
use App\Models\Organization;
use App\Services\OrganizationContractorService;
use Illuminate\Http\JsonResponse;

class OrganizationContractorController extends Controller
{
    public function __construct(
        private OrganizationContractorService $service
    ) {
    }

    public function contractorsForTenant(): JsonResponse
    {
        return response()->json($this->service->allContractorsForTenant());
    }

    public function index(Organization $organization): JsonResponse
    {
        return response()->json($this->service->list($organization));
    }

    public function attach(Organization $organization, Contractor $contractor): JsonResponse
    {
        return response()->json(
            $this->service->attach($organization, $contractor),
            201
        );
    }

    public function detach(Organization $organization, Contractor $contractor): JsonResponse
    {
        $this->service->detach($organization, $contractor);

        return response()->json([
            'message' => 'Alt yüklenici organizasyon eşleştirmesi kaldırıldı.',
        ]);
    }
}
