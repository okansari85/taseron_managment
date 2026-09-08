<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationBusinessEntityRequest;
use App\Http\Requests\UpdateLocationBusinessEntityRequest;
use App\Models\BusinessEntity;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Domain\Tenancy\TenantContext;
use App\Services\LocationBusinessEntityService;
use Illuminate\Http\JsonResponse;

class LocationBusinessEntityController extends Controller
{
    public function __construct(
        private LocationBusinessEntityService $service
    ) {
    }

    public function index(Location $location): JsonResponse
    {
        return response()->json($this->service->all($location));
    }

    public function forTenant(TenantContext $tenantContext): JsonResponse
    {
        return response()->json($this->service->forTenant($tenantContext->id()));
    }

    public function store(
        StoreLocationBusinessEntityRequest $request,
        Location $location
    ): JsonResponse {
        $businessEntity = BusinessEntity::query()->findOrFail(
            $request->validated('business_entity_id')
        );

        $this->service->attach(
            $location,
            $businessEntity,
            [
                'brand_ids' => $request->validated('brand_ids', []),
                'operational_region_id' => $request->validated('operational_region_id'),
                'activity' => $request->validated('activity'),
                'sub_activity' => $request->validated('sub_activity'),
                'nace_code' => $request->validated('nace_code'),
                'hazard_class' => $request->validated('hazard_class'),
                'sgk_workplace_number' => $request->validated('sgk_workplace_number'),
                'address' => $request->validated('address'),
                'is_active' => $request->validated('is_active', true),
                'photos' => $request->file('photos', []),
            ]
        );

        return response()->json([
            'message' => 'Business Entity lokasyona başarıyla bağlandı.',
        ], 201);
    }

    public function update(
        UpdateLocationBusinessEntityRequest $request,
        Location $location,
        LocationBusinessEntity $locationBusinessEntity
    ): JsonResponse {
        $this->service->update(
            $location,
            $locationBusinessEntity,
            $request->validated()
        );

        return response()->json([
            'message' => 'Lokasyon Business Entity bilgileri başarıyla güncellendi.',
        ]);
    }

    public function destroy(
        Location $location,
        LocationBusinessEntity $locationBusinessEntity
    ): JsonResponse {
        $this->service->detach($location, $locationBusinessEntity);

        return response()->json([
            'message' => 'Business Entity lokasyondan başarıyla çıkarıldı.',
        ]);
    }
}
