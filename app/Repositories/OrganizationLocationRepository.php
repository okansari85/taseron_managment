<?php

namespace App\Repositories;

use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationLocation;
use App\Repositories\Contracts\OrganizationLocationRepositoryInterface;
use Illuminate\Support\Collection;

class OrganizationLocationRepository implements OrganizationLocationRepositoryInterface
{
    public function list(Organization $organization): Collection
    {
        return OrganizationLocation::query()
            ->where('organization_id', $organization->id)
            ->with('location')
            ->latest('id')
            ->get();
    }

    public function attach(Organization $organization, Location $location): OrganizationLocation
    {
        return OrganizationLocation::firstOrCreate([
            'tenant_id' => $organization->tenant_id,
            'organization_id' => $organization->id,
            'location_id' => $location->id,
        ]);
    }

    public function detach(Organization $organization, Location $location): void
    {
        OrganizationLocation::query()
            ->where('organization_id', $organization->id)
            ->where('location_id', $location->id)
            ->delete();
    }
}
