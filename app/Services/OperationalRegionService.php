<?php

namespace App\Services;

use App\Models\Location;
use App\Models\OperationalRegion;
use App\Repositories\Contracts\OperationalRegionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class OperationalRegionService
{
    public function __construct(
        private OperationalRegionRepositoryInterface $repository
    ) {
    }

    public function all(Location $location): Collection
    {
        return $this->repository->all($location);
    }

    public function find(Location $location, int $id): OperationalRegion
    {
        return $this->repository->find($location, $id);
    }

    public function create(Location $location, array $data): OperationalRegion
    {
        $this->ensureTenant($location);

        return DB::transaction(function () use ($location, $data) {
            return $this->repository->create($location, $data);
        });
    }

    public function update(
        Location $location,
        OperationalRegion $operationalRegion,
        array $data
    ): OperationalRegion {
        $this->ensureTenant($location);
        $this->ensureBelongsToLocation($location, $operationalRegion);

        return DB::transaction(function () use ($operationalRegion, $data) {
            return $this->repository->update($operationalRegion, $data);
        });
    }

    public function delete(
        Location $location,
        OperationalRegion $operationalRegion
    ): void {
        $this->ensureTenant($location);
        $this->ensureBelongsToLocation($location, $operationalRegion);

        DB::transaction(function () use ($operationalRegion) {
            $this->repository->delete($operationalRegion);
        });
    }

    private function ensureTenant(Location $location): void
    {
        $context = app(\App\Domain\Tenancy\TenantContext::class);

        if (! $context->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        if ($location->tenant_id !== $context->id()) {
            throw new RuntimeException(
                'Bu lokasyona erişim yetkiniz yok.'
            );
        }
    }

    private function ensureBelongsToLocation(
        Location $location,
        OperationalRegion $operationalRegion
    ): void {
        if ($operationalRegion->location_id !== $location->id) {
            throw new RuntimeException(
                'Operasyonel alan bu lokasyona ait değil.'
            );
        }
    }
}
