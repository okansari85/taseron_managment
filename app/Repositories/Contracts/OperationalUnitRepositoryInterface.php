<?php

namespace App\Repositories\Contracts;

use App\Models\Location;
use App\Models\OperationalUnit;
use Illuminate\Database\Eloquent\Collection;

interface OperationalUnitRepositoryInterface
{
    public function all(Location $location): Collection;

    public function find(Location $location, int $id): OperationalUnit;

    public function create(Location $location, array $data): OperationalUnit;

    public function update(OperationalUnit $operationalUnit, array $data): OperationalUnit;

    public function delete(OperationalUnit $operationalUnit): void;
}
