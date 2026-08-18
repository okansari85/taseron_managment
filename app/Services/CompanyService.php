<?php

namespace App\Services;

use App\Models\Company;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    public function __construct(
        private CompanyRepositoryInterface $repository
    ) {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): Company
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Company
    {
        return DB::transaction(function () use ($data) {
            return $this->repository->create($data);
        });
    }

    public function update(
        Company $company,
        array $data
    ): Company {
        return DB::transaction(function () use ($company, $data) {
            return $this->repository->update(
                $company,
                $data
            );
        });
    }

    public function delete(Company $company): void
    {
        DB::transaction(function () use ($company) {
            $this->repository->delete($company);
        });
    }
}