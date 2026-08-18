<?php

namespace App\Repositories\Contracts;

use App\Models\Location;
use App\Models\Organization;

interface OrganizationLocationRepositoryInterface
{
    public function attach(
        Organization $organization,
        Location $location
    ): void;

    public function detach(
        Organization $organization,
        Location $location
    ): void;

    public function sync(
        Organization $organization,
        array $locationIds
    ): void;
}
