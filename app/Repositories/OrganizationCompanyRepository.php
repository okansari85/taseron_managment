<?php

namespace App\Repositories;

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
        Company $company
    ): void {
        $organization->companies()->syncWithoutDetaching([
            $company->id,
        ]);
    }

    public function detach(
        Organization $organization,
        Company $company
    ): void {
        $organization->companies()->detach(
            $company->id
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
