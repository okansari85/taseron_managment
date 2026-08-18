<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    public function __construct(
        private OrganizationService $service
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            $this->service->all()
        );
    }

    public function store(
        StoreOrganizationRequest $request
    ): JsonResponse {
        $organization = $this->service->create(
            $request->validated()
        );

        return response()->json(
            $organization,
            201
        );
    }

    public function show(
        Organization $organization
    ): JsonResponse {
        return response()->json(
            $this->service->find($organization->id)
        );
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization
    ): JsonResponse {
        $organization = $this->service->update(
            $organization,
            $request->validated()
        );

        return response()->json(
            $organization
        );
    }

    public function destroy(
        Organization $organization
    ): JsonResponse {
        $this->service->delete($organization);

        return response()->json([
            'message' => 'Organizasyon başarıyla silindi.',
        ]);
    }
}