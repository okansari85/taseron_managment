<?php

namespace App\Repositories;

use App\Models\BusinessEntity;
use App\Models\Company;
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

    public function allForTenant(): Collection
    {
        return Company::query()
            ->whereHas('businessEntity', function ($query) {
                $query->where('type', 'company');
            })
            ->whereHas('organizations', function ($query) {
                $query->where('type', 'group');
            })
            ->with([
                'businessEntity',
                'organizations' => function ($query) {
                    $query->where('type', 'group');
                },
            ])
            ->withCount('brands')
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
