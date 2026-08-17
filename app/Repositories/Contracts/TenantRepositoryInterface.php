<?php

namespace App\Repositories\Contracts;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;

interface TenantRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): Tenant;

    public function create(array $data): Tenant;

    public function update(Tenant $tenant, array $data): Tenant;

    public function delete(Tenant $tenant): void;
}
