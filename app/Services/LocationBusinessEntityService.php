<?php

namespace App\Services;

use App\Models\BusinessEntity;
use App\Models\Location;
use App\Models\OperationalRegion;
use App\Repositories\Contracts\LocationBusinessEntityRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Illuminate\Validation\ValidationException;

class LocationBusinessEntityService
{
    public function __construct(
        private LocationBusinessEntityRepositoryInterface $repository
    ) {
    }

    public function all(Location $location): Collection
    {
        return $this->repository->all($location);
    }

    public function attach(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void {
        $this->validateBusinessEntity($businessEntity, $pivotData, $location);

        DB::transaction(function () use ($location, $businessEntity, $pivotData) {
            $this->repository->attach($location, $businessEntity, $pivotData);
        });
    }

    public function update(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void {
        $this->validateBusinessEntity($businessEntity, $pivotData, $location);

        DB::transaction(function () use ($location, $businessEntity, $pivotData) {
            $this->repository->update($location, $businessEntity, $pivotData);
        });
    }

    public function detach(Location $location, BusinessEntity $businessEntity): void
    {
        DB::transaction(function () use ($location, $businessEntity) {
            $this->repository->detach($location, $businessEntity);
        });
    }

    private function validateBusinessEntity(
        BusinessEntity $businessEntity,
        array &$pivotData,
        Location $location
    ): void {
        $operationalRegionId = $pivotData['operational_region_id'] ?? null;

        if ($operationalRegionId !== null) {
            $operationalRegion = OperationalRegion::query()
                ->whereKey($operationalRegionId)
                ->first();

            if ($operationalRegion === null) {
                throw ValidationException::withMessages([
                    'operational_region_id' => ['Seçilen operasyonel alan bulunamadı.'],
                ]);
            }

            if ($operationalRegion->location_id !== $location->id) {
                throw ValidationException::withMessages([
                    'operational_region_id' => [
                        'Seçilen operasyonel alan bu lokasyona ait değil.',
                    ],
                ]);
            }
        }

        if ($businessEntity->type === 'company') {
            return;
        }

        if ($businessEntity->type !== 'contractor') {
            throw new LogicException(
                'Bu Business Entity tipi lokasyona bağlanamaz.'
            );
        }

        $contractor = $businessEntity->contractor;

        if ($contractor === null) {
            throw new LogicException(
                'Business Entity için Contractor kaydı bulunamadı.'
            );
        }

        if ($contractor->contractor_type === 'temporary') {
            throw ValidationException::withMessages([
                'business_entity_id' => [
                    'Geçici taşeron lokasyona bağlanamaz.',
                ],
            ]);
        }
    }
}
