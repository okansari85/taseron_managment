<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Tenant;
use App\Services\OrganizationService;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;

class TenantController extends Controller
{
    public function __construct(
        private TenantService $service,
        private OrganizationService $organizationService
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all(),
        ]);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = $this->service->create(
            $request->validated()
        );

        return response()->json([
            'message' => 'Tenant created successfully.',
            'data' => $tenant,
        ], 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant = $this->service->find($tenant->id);
        $rootOrganization = $this->organizationService
            ->getRootByTenantId($tenant->id);

        return response()->json([
            'data' => [
                ...$tenant->toArray(),
                'root_organization' => $rootOrganization,
            ],
        ]);
    }

    public function update(
        UpdateTenantRequest $request,
        Tenant $tenant
    ): JsonResponse {
        $tenant = $this->service->update(
            $tenant,
            $request->validated()
        );

        return response()->json([
            'message' => 'Tenant updated successfully.',
            'data' => $tenant,
        ]);
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $this->service->delete($tenant);

        return response()->json([
            'message' => 'Tenant deleted successfully.',
        ]);
    }
}
