<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncOrganizationCompaniesRequest;
use App\Models\BusinessEntity;
use App\Models\Organization;
use App\Services\OrganizationCompanyService;
use Illuminate\Http\JsonResponse;

class OrganizationCompanyController extends Controller
{
    public function __construct(
        private OrganizationCompanyService $service
    ) {
    }

    public function indexForTenant(): JsonResponse
    {
        return response()->json(
            $this->service->allForTenant()
        );
    }

    public function index(
        Organization $organization
    ): JsonResponse {
        return response()->json(
            $this->service->all($organization)
        );
    }

    public function attach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): JsonResponse {
        $this->service->attach(
            $organization,
            $businessEntity
        );

        return response()->json([
            'message' =>
                'Şirket organizasyona başarıyla bağlandı.',
        ]);
    }

    public function detach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): JsonResponse {
        $this->service->detach(
            $organization,
            $businessEntity
        );

        return response()->json([
            'message' =>
                'Şirket organizasyondan başarıyla çıkarıldı.',
        ]);
    }

    public function sync(
        SyncOrganizationCompaniesRequest $request,
        Organization $organization
    ): JsonResponse {
        $this->service->sync(
            $organization,
            $request->validated('business_entity_ids')
        );

        return response()->json([
            'message' =>
                'Organizasyon şirketleri başarıyla güncellendi.',
        ]);
    }
}
