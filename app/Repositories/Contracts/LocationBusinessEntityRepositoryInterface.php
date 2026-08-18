<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessEntity;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

interface LocationBusinessEntityRepositoryInterface
{
    public function all(Location $location): Collection;

    public function attach(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void;

    public function update(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void;

    public function detach(
        Location $location,
        BusinessEntity $businessEntity
    ): void;
}
