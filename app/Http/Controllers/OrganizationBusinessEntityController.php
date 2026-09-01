<?php

namespace App\Http\Controllers;

use App\Models\BusinessEntity;
use App\Models\Organization;
use App\Services\OrganizationBusinessEntityService;
use Illuminate\Http\JsonResponse;

class OrganizationBusinessEntityController extends Controller
{
    public function __construct(
        private OrganizationBusinessEntityService $service
    ) {
    }

    public function contractorsForTenant(): JsonResponse
    {
        return response()->json(
            $this->service->allContractorsForTenant()
        );
    }

    public function attach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): JsonResponse {
        $this->service->attachBusinessEntity($organization, $businessEntity);

        return response()->json([
            'message' => 'Alt yüklenici organizasyona başarıyla bağlandı.',
        ]);
    }

    public function detach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): JsonResponse {
        $this->service->detachBusinessEntity($organization, $businessEntity);

        return response()->json([
            'message' => 'Alt yüklenici organizasyondan başarıyla çıkarıldı.',
        ]);
    }
}
