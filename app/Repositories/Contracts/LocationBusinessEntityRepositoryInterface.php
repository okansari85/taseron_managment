<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessEntity;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use Illuminate\Database\Eloquent\Collection;

interface LocationBusinessEntityRepositoryInterface
{
    public function all(Location $location): Collection;

    public function forTenant(int $tenantId): Collection;

    public function attach(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): LocationBusinessEntity;

    public function update(
        LocationBusinessEntity $locationBusinessEntity,
        array $pivotData
    ): void;

    public function detach(
        LocationBusinessEntity $locationBusinessEntity
    ): void;
}
