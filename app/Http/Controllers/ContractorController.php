<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContractorRequest;
use App\Http\Requests\UpdateContractorRequest;
use App\Models\Contractor;
use App\Services\ContractorService;
use App\Services\OrganizationContractorService;
use Illuminate\Http\JsonResponse;

class ContractorController extends Controller
{
    public function __construct(
        private ContractorService $service,
        private OrganizationContractorService $organizationContractorService
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            $this->service->all()
        );
    }

    public function store(
        StoreContractorRequest $request
    ): JsonResponse {
        $contractor = $this->service->create(
            $request->validated()
        );

        return response()->json(
            $contractor->load('businessEntity'),
            201
        );
    }

    public function show(
        Contractor $contractor
    ): JsonResponse {
        return response()->json(
            $this->service->find($contractor->id)
        );
    }

    public function update(
        UpdateContractorRequest $request,
        Contractor $contractor
    ): JsonResponse {
        $contractor = $this->service->update(
            $contractor,
            $request->validated()
        );

        return response()->json(
            $contractor->load('businessEntity')
        );
    }

    public function locations(
        Contractor $contractor
    ): JsonResponse {
        return response()->json(
            $this->organizationContractorService->locationsForContractor($contractor)
        );
    }

    public function destroy(
        Contractor $contractor
    ): JsonResponse {
        $this->service->delete($contractor);

        return response()->json([
            'message' => 'Taşeron başarıyla silindi.',
        ]);
    }
}
