<?php

namespace App\Repositories;

use App\Models\Location;
use App\Models\OperationalRegion;
use App\Repositories\Contracts\OperationalRegionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class OperationalRegionRepository implements OperationalRegionRepositoryInterface
{
    public function all(Location $location): Collection
    {
        return $location->operationalRegions()->orderBy('name')->get();
    }

    public function find(Location $location, int $id): OperationalRegion
    {
        return $location->operationalRegions()->whereKey($id)->firstOrFail();
    }

    public function create(Location $location, array $data): OperationalRegion
    {
        return $location->operationalRegions()->create([
            'tenant_id' => $location->tenant_id,
            'name' => $data['name'],
            'type' => $data['type'],
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(OperationalRegion $operationalRegion, array $data): OperationalRegion
    {
        $operationalRegion->update($data);
        return $operationalRegion->refresh();
    }

    public function delete(OperationalRegion $operationalRegion): void
    {
        $operationalRegion->delete();
    }
}
