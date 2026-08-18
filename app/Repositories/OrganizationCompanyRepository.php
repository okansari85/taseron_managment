<?php

namespace App\Repositories;

use App\Models\BusinessEntity;
use App\Models\Organization;
use App\Repositories\Contracts\OrganizationCompanyRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class OrganizationCompanyRepository implements OrganizationCompanyRepositoryInterface
{
    public function all(
        Organization $organization
    ): Collection {
        return $organization
            ->companies()
            ->orderBy('name')
            ->get();
    }

    public function attach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void {
        $organization->companies()->syncWithoutDetaching([
            $businessEntity->id,
        ]);
    }

    public function detach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void {
        $organization->companies()->detach(
            $businessEntity->id
        );
    }

    public function sync(
        Organization $organization,
        array $businessEntityIds
    ): void {
        $organization->companies()->sync(
            $businessEntityIds
        );
    }
}
