<?php

namespace App\Repositories;

use App\Models\Tenant;
use App\Repositories\Contracts\TenantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class TenantRepository implements TenantRepositoryInterface
{
    public function all(): Collection
    {
        return Tenant::query()
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): Tenant
    {
        return Tenant::query()->findOrFail($id);
    }

    public function create(array $data): Tenant
    {
        return Tenant::query()->create($data);
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $tenant->update($data);

        return $tenant->refresh();
    }

    public function delete(Tenant $tenant): void
    {
        $tenant->delete();
    }
}
