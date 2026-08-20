<?php

namespace App\Repositories\Contracts;

use App\Models\Location;
use App\Models\OperationalRegion;
use Illuminate\Database\Eloquent\Collection;

interface OperationalRegionRepositoryInterface
{
    public function all(Location $location): Collection;
    public function find(Location $location, int $id): OperationalRegion;
    public function create(Location $location, array $data): OperationalRegion;
    public function update(OperationalRegion $operationalRegion, array $data): OperationalRegion;
    public function delete(OperationalRegion $operationalRegion): void;
}
