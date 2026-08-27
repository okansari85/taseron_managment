<?php

namespace App\Repositories\Contracts;

use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationLocation;
use Illuminate\Support\Collection;

interface OrganizationLocationRepositoryInterface
{
    public function list(Organization $organization): Collection;

    public function attach(Organization $organization, Location $location): OrganizationLocation;

    public function detach(Organization $organization, Location $location): void;
}
