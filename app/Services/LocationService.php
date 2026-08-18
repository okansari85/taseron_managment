<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Repositories\Contracts\LocationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class LocationService
{
    public function __construct(
        private LocationRepositoryInterface $repository,
        private TenantContext $tenantContext
    ) {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): Location
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Location
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        $data['tenant_id'] = $this->tenantContext->id();

        return DB::transaction(function () use ($data) {
            return $this->repository->create($data);
        });
    }

    public function update(
        Location $location,
        array $data
    ): Location {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        if ($location->tenant_id !== $this->tenantContext->id()) {
            throw new LogicException(
                'Location mevcut tenant içerisinde değildir.'
            );
        }

        unset($data['tenant_id']);

        return DB::transaction(function () use (
            $location,
            $data
        ) {
            return $this->repository->update(
                $location,
                $data
            );
        });
    }

    public function delete(Location $location): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        if ($location->tenant_id !== $this->tenantContext->id()) {
            throw new LogicException(
                'Location mevcut tenant içerisinde değildir.'
            );
        }

        DB::transaction(function () use ($location) {
            $this->repository->delete($location);
        });
    }
}
