<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class OrganizationRepository implements OrganizationRepositoryInterface
{
    public function all(): Collection
    {
        return Organization::query()
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }

    public function create(array $data): Organization
    {
        return Organization::query()->create($data);
    }

    public function update(Organization $organization, array $data): Organization
    {
        $organization->update($data);

        return $organization->refresh();
    }

    public function delete(Organization $organization): void
    {
        $organization->delete();
    }
}