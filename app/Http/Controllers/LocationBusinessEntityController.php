<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationBusinessEntityRequest;
use App\Http\Requests\UpdateLocationBusinessEntityRequest;
use App\Models\BusinessEntity;
use App\Models\Location;
use App\Services\LocationBusinessEntityService;
use Illuminate\Http\JsonResponse;

class LocationBusinessEntityController extends Controller
{
    public function __construct(
        private LocationBusinessEntityService $service
    ) {
    }

    public function index(
        Location $location
    ): JsonResponse {
        return response()->json(
            $this->service->all($location)
        );
    }

    public function store(
        StoreLocationBusinessEntityRequest $request,
        Location $location
    ): JsonResponse {
        $businessEntity = BusinessEntity::query()
            ->findOrFail(
                $request->validated('business_entity_id')
            );

        $this->service->attach(
            $location,
            $businessEntity,
            [
                'nace_code' =>
                    $request->validated('nace_code'),

                'hazard_class' =>
                    $request->validated('hazard_class'),

                'sgk_workplace_number' =>
                    $request->validated('sgk_workplace_number'),
            ]
        );

        return response()->json([
            'message' =>
                'Business Entity lokasyona başarıyla bağlandı.',
        ], 201);
    }

    public function update(
        UpdateLocationBusinessEntityRequest $request,
        Location $location,
        BusinessEntity $businessEntity
    ): JsonResponse {
        $this->service->update(
            $location,
            $businessEntity,
            $request->validated()
        );

        return response()->json([
            'message' =>
                'Lokasyon Business Entity bilgileri başarıyla güncellendi.',
        ]);
    }

    public function destroy(
        Location $location,
        BusinessEntity $businessEntity
    ): JsonResponse {
        $this->service->detach(
            $location,
            $businessEntity
        );

        return response()->json([
            'message' =>
                'Business Entity lokasyondan başarıyla çıkarıldı.',
        ]);
    }
}
