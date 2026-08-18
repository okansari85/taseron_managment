<?php

namespace App\Repositories;

use App\Models\Location;
use App\Models\Organization;
use App\Repositories\Contracts\OrganizationLocationRepositoryInterface;

class OrganizationLocationRepository implements OrganizationLocationRepositoryInterface
{
    public function attach(
        Organization $organization,
        Location $location
    ): void {
        $organization->locations()->syncWithoutDetaching([
            $location->id,
        ]);
    }

    public function detach(
        Organization $organization,
        Location $location
    ): void {
        $organization->locations()->detach($location->id);
    }

    public function sync(
        Organization $organization,
        array $locationIds
    ): void {
        $organization->locations()->sync($locationIds);
    }
}
