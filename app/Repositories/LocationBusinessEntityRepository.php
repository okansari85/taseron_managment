<?php

namespace App\Repositories;

use App\Models\BusinessEntity;
use App\Models\Location;
use App\Repositories\Contracts\LocationBusinessEntityRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class LocationBusinessEntityRepository implements LocationBusinessEntityRepositoryInterface
{
    public function all(Location $location): Collection
    {
        return $location
            ->businessEntities()
            ->orderBy('name')
            ->get();
    }

    public function attach(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void {
        $location->businessEntities()->attach(
            $businessEntity->id,
            $pivotData
        );
    }

    public function update(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void {
        $location->businessEntities()->updateExistingPivot(
            $businessEntity->id,
            $pivotData
        );
    }

    public function detach(
        Location $location,
        BusinessEntity $businessEntity
    ): void {
        $location->businessEntities()->detach(
            $businessEntity->id
        );
    }
}
