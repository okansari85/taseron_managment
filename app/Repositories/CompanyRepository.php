<?php

namespace App\Repositories;

use App\Models\Company;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class CompanyRepository implements CompanyRepositoryInterface
{
    public function all(): Collection
    {
        return Company::query()
            ->with('organizations')
            ->withCount('brands')
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): Company
    {
        return Company::query()->findOrFail($id);
    }

    public function create(array $data): Company
    {
        return Company::query()->create($data);
    }

    public function update(Company $company, array $data): Company
    {
        $company->update($data);

        return $company->refresh();
    }

    public function delete(Company $company): void
    {
        $company->delete();
    }
}