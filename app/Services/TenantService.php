<?php

namespace App\Services;

use App\Models\Tenant;
use App\Repositories\Contracts\TenantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TenantService
{
    public function __construct(
        private TenantRepositoryInterface $repository
    ) {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): Tenant
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            return $this->repository->create($data);
        });
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data) {
            return $this->repository->update($tenant, $data);
        });
    }

    public function delete(Tenant $tenant): void
    {
        $logoPath = $tenant->logo_path;

        DB::transaction(function () use ($tenant) {
            $this->repository->delete($tenant);
        });

        if ($logoPath) {
            Storage::disk('public')->delete($logoPath);
        }
    }
}
