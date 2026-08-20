<?php

namespace App\Repositories;

use App\Models\Location;
use App\Models\OperationalUnit;
use App\Repositories\Contracts\OperationalUnitRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class OperationalUnitRepository implements OperationalUnitRepositoryInterface
{
    public function all(Location $location): Collection
    {
        return $location
            ->operationalUnits()
            ->orderBy('name')
            ->get();
    }

    public function find(Location $location, int $id): OperationalUnit
    {
        return $location
            ->operationalUnits()
            ->whereKey($id)
            ->firstOrFail();
    }

    public function create(Location $location, array $data): OperationalUnit
    {
        return $location->operationalUnits()->create([
            'tenant_id' => $location->tenant_id,
            'name' => $data['name'],
            'type' => $data['type'],
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(
        OperationalUnit $operationalUnit,
        array $data
    ): OperationalUnit {
        $operationalUnit->update($data);

        return $operationalUnit->refresh();
    }

    public function delete(OperationalUnit $operationalUnit): void
    {
        $operationalUnit->delete();
    }
}
