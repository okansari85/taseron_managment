<?php

namespace App\Repositories;

use App\Models\BusinessEntity;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
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

    public function forTenant(int $tenantId): Collection
    {
        return LocationBusinessEntity::query()
            ->whereHas('location', fn ($query) => $query->where('tenant_id', $tenantId))
            ->with([
                'location:id,name,city_id,district_id',
                'location.city:id,name',
                'location.district:id,name',
                'businessEntity:id,name',
                'businessEntity.company:id,name,business_entity_id',
                'brands:id,name',
                'operationalRegion:id,name',
                'photos',
            ])
            ->orderBy('id')
            ->get();
    }

    public function attach(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): LocationBusinessEntity {
        $location->businessEntities()->attach(
            $businessEntity->id,
            $pivotData
        );

        // The same business entity can now be attached to a location more than
        // once (different operational areas), so the freshly created row must be
        // looked up by its own id rather than by (location_id, business_entity_id).
        return LocationBusinessEntity::query()
            ->where('location_id', $location->id)
            ->where('business_entity_id', $businessEntity->id)
            ->latest('id')
            ->firstOrFail();
    }

    public function update(
        LocationBusinessEntity $locationBusinessEntity,
        array $pivotData
    ): void {
        $locationBusinessEntity->update($pivotData);
    }

    public function detach(
        LocationBusinessEntity $locationBusinessEntity
    ): void {
        $locationBusinessEntity->delete();
    }
}
