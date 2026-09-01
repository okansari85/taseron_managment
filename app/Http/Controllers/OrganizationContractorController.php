<?php

namespace App\Http\Controllers;

use App\Models\Contractor;
use App\Models\Organization;
use App\Services\OrganizationContractorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        return response()->json($this->service->attach($organization, $contractor), 201);
    }

    public function bulkAttach(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_ids' => ['required', 'array', 'min:1'],
            'organization_ids.*' => ['integer', 'distinct'],
            'contractor_ids' => ['required', 'array', 'min:1'],
            'contractor_ids.*' => ['integer', 'distinct'],
        ]);

        $count = $this->service->bulkAttach(
            $validated['organization_ids'],
            $validated['contractor_ids']
        );

        return response()->json([
            'message' => 'Alt yüklenici organizasyon eşleştirmeleri uygulandı.',
            'count' => $count,
        ]);
    }

    public function detach(Organization $organization, Contractor $contractor): JsonResponse
    {
        $this->service->detach($organization, $contractor);

        return response()->json([
            'message' => 'Alt yüklenici organizasyon eşleştirmesi kaldırıldı.',
        ]);
    }
}
