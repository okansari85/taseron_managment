<?php

namespace App\Repositories;

use App\Models\Location;
use App\Repositories\Contracts\LocationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class LocationRepository implements LocationRepositoryInterface
{
    private const RELATIONS = [
        'city:id,name',
        'district:id,city_id,name',
        'businessEntities:id,name,type',
        'businessEntities.company:id,name,business_entity_id',
        'businessEntities.company.brands:id,name',
    ];

    public function all(): Collection
    {
        return Location::query()->with(self::RELATIONS)->orderBy('name')->get();
    }

    public function find(int $id): Location
    {
        return Location::query()->with(self::RELATIONS)->findOrFail($id);
    }

    public function create(array $data): Location
    {
        return Location::query()->create($data)->load(self::RELATIONS);
    }

    public function update(Location $location, array $data): Location
    {
        $location->update($data);
        return $location->refresh()->load(self::RELATIONS);
    }

    public function delete(Location $location): void { $location->delete(); }
}
