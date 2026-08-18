<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessEntity;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;

interface OrganizationCompanyRepositoryInterface
{
    public function all(
        Organization $organization
    ): Collection;

    public function attach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void;

    public function detach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void;

    public function sync(
        Organization $organization,
        array $businessEntityIds
    ): void;
}
