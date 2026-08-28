<?php

namespace App\Repositories\Contracts;

use App\Models\Company;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;

interface OrganizationCompanyRepositoryInterface
{
    public function all(
        Organization $organization
    ): Collection;

    public function allForTenant(): Collection;

    public function attach(
        Organization $organization,
        Company $company
    ): void;

    public function detach(
        Organization $organization,
        Company $company
    ): void;

    /**
     * @param array<int, int> $companyIds
     */
    public function sync(
        Organization $organization,
        array $companyIds
    ): void;
}
