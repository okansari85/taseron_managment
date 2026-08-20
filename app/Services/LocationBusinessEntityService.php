<?php

namespace App\Services;

use App\Models\BusinessEntity;
use App\Models\Location;
use App\Models\OperationalRegion;
use App\Repositories\Contracts\LocationBusinessEntityRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
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
        $businessEntities = $this->repository->all($location);

        $businessEntities->each(function (BusinessEntity $businessEntity): void {
            if ($businessEntity->pivot instanceof Pivot) {
                $businessEntity->pivot->load('brands');
            }
        });

        return $businessEntities;
    }

    public function attach(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void {
        $brandIds = $pivotData['brand_ids'] ?? [];
        unset($pivotData['brand_ids']);

        $this->validateBusinessEntity($businessEntity, $pivotData, $location);
        $this->validateBrands($businessEntity, $brandIds);

        DB::transaction(function () use (
            $location,
            $businessEntity,
            $pivotData,
            $brandIds
        ) {
            $this->repository->attach($location, $businessEntity, $pivotData);
            $this->syncBrands($location, $businessEntity, $brandIds);
        });
    }

    public function update(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void {
        $brandIds = $pivotData['brand_ids'] ?? null;
        unset($pivotData['brand_ids']);

        $this->validateBusinessEntity($businessEntity, $pivotData, $location);

        if ($brandIds !== null) {
            $this->validateBrands($businessEntity, $brandIds);
        }

        DB::transaction(function () use (
            $location,
            $businessEntity,
            $pivotData,
            $brandIds
        ) {
            $this->repository->update($location, $businessEntity, $pivotData);

            if ($brandIds !== null) {
                $this->syncBrands($location, $businessEntity, $brandIds);
            }
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

    private function validateBrands(
        BusinessEntity $businessEntity,
        array $brandIds
    ): void {
        if ($brandIds === []) {
            return;
        }

        if ($businessEntity->type !== 'company' || $businessEntity->company === null) {
            throw ValidationException::withMessages([
                'brand_ids' => [
                    'Marka yalnızca şirket tipindeki Business Entity için tanımlanabilir.',
                ],
            ]);
        }

        $companyBrandCount = DB::table('company_brands')
            ->where('company_id', $businessEntity->company->id)
            ->whereIn('brand_id', array_values(array_unique($brandIds)))
            ->count();

        if ($companyBrandCount !== count(array_unique($brandIds))) {
            throw ValidationException::withMessages([
                'brand_ids' => [
                    'Seçilen markalardan biri bu şirkete bağlı değil.',
                ],
            ]);
        }
    }

    private function syncBrands(
        Location $location,
        BusinessEntity $businessEntity,
        array $brandIds
    ): void {
        $pivot = DB::table('location_business_entities')
            ->where('location_id', $location->id)
            ->where('business_entity_id', $businessEntity->id)
            ->first();

        if ($pivot === null) {
            throw new LogicException(
                'Lokasyon Business Entity kaydı bulunamadı.'
            );
        }

        DB::table('location_business_entity_brands')
            ->where('location_business_entity_id', $pivot->id)
            ->delete();

        if ($brandIds === []) {
            return;
        }

        $rows = [];
        $now = now();

        foreach (array_values(array_unique($brandIds)) as $brandId) {
            $rows[] = [
                'location_business_entity_id' => $pivot->id,
                'brand_id' => $brandId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('location_business_entity_brands')->insert($rows);
    }
}
