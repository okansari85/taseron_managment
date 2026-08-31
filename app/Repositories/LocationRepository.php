<?php

namespace App\Repositories;

use App\Models\Location;
use App\Repositories\Contracts\LocationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class LocationRepository implements LocationRepositoryInterface
{
    public function all(): Collection
    {
        return Location::query()
            ->with('organizations:id,name')
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): Location
    {
        return Location::query()->with('organizations:id,name')->findOrFail($id);
    }

    public function create(array $data): Location
    {
        return Location::query()->create($data);
    }

    public function update(Location $location, array $data): Location
    {
        $location->update($data);
        return $location->refresh()->load('organizations:id,name');
    }

    public function delete(Location $location): void
    {
        $location->delete();
    }
}
