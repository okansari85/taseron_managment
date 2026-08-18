<?php

namespace App\Repositories\Contracts;

use App\Models\Company;
use Illuminate\Database\Eloquent\Collection;

interface CompanyRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): Company;

    public function create(array $data): Company;

    public function update(Company $company, array $data): Company;

    public function delete(Company $company): void;
}